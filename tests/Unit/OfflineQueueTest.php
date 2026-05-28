<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Config\Options;
use AllStak\Tests\Support\MockServerTestCase;

/**
 * Offline / persistent event queue wired through {@see AllStak}: telemetry that
 * cannot be delivered at shutdown is PII-scrubbed, written to the filesystem
 * spool, and replayed on the next init — surviving a process restart and a
 * network outage. Also covers the enableOfflineQueue opt-out and the
 * session-calls-are-never-persisted invariant end-to-end.
 *
 * Each test uses a dedicated throwaway spool dir so the two SDK "process"
 * lifecycles (the failing one, then the recovering one) share state only via
 * disk — exactly as a real restart would.
 */
final class OfflineQueueTest extends MockServerTestCase
{
    private string $spoolDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->spoolDir = sys_get_temp_dir() . '/allstak-oq-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->spoolDir !== '' && is_dir($this->spoolDir)) {
            foreach (glob($this->spoolDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->spoolDir);
        }
        parent::tearDown();
    }

    /** Count spool files currently on disk for this test's dir. */
    private function spoolCount(): int
    {
        return count(glob($this->spoolDir . '/*.aspool.json') ?: []);
    }

    // ─── persist on send failure (outage at shutdown) ────────────────

    public function testUndeliveredLogsArePersistedOnShutdown(): void
    {
        // Point "process 1" at a dead port so the shutdown drain cannot deliver.
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://127.0.0.1:1', // nothing listening
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 200,
            'totalTimeoutMs' => 400,
            'offlineQueuePath' => $this->spoolDir,
        ]);

        $sdk->captureLog('error', 'outage log A');
        $sdk->captureLog('error', 'outage log B');

        $this->assertNotNull($sdk->getSpool(), 'spool must be available for a writable dir');

        // Simulate process exit: the shutdown drain fails to deliver and spools.
        $sdk->shutdown();

        $this->assertSame(2, $this->spoolCount(), 'both undelivered logs must be persisted');
        AllStak::reset();
    }

    // ─── drain + resend on next init (restart recovery) ──────────────

    public function testPersistedEventsAreReplayedOnNextInit(): void
    {
        // Process 1: dead host → events land on disk.
        $sdk1 = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://127.0.0.1:1',
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 200,
            'totalTimeoutMs' => 400,
            'offlineQueuePath' => $this->spoolDir,
        ]);
        $sdk1->captureLog('error', 'survivor-log');
        $sdk1->shutdown();
        AllStak::reset();

        $this->assertSame(1, $this->spoolCount(), 'event persisted by process 1');

        // Process 2: network is back (mock server up) → init drains the spool.
        $sdk2 = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => $this->host, // live mock server
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 1000,
            'totalTimeoutMs' => 2000,
            'offlineQueuePath' => $this->spoolDir,
        ]);

        // Replay happens on init; the accepted (202) entry is removed.
        $this->assertSame(0, $this->spoolCount(), 'replayed entry removed after 2xx');

        $logs = $this->requestsForPath('/ingest/v1/logs');
        $this->assertCount(1, $logs, 'persisted log was re-sent on init');
        $this->assertSame('survivor-log', $logs[0]['payload']['message']);

        $sdk2->flush();
        AllStak::reset();
    }

    // ─── opt-out disables persistence ────────────────────────────────

    public function testOptOutDisablesSpool(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://127.0.0.1:1',
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 200,
            'totalTimeoutMs' => 400,
            'offlineQueuePath' => $this->spoolDir,
            'enableOfflineQueue' => false,
        ]);

        $this->assertNull($sdk->getSpool(), 'spool must be null when opted out');

        $sdk->captureLog('error', 'dropped on shutdown');
        $sdk->shutdown();

        $this->assertSame(0, $this->spoolCount(), 'opt-out keeps legacy in-memory-only behavior');
        AllStak::reset();
    }

    // ─── session calls are never persisted (end-to-end) ──────────────

    public function testSessionCallsAreNeverPersistedEvenOnOutage(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://127.0.0.1:1',
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 200,
            'totalTimeoutMs' => 400,
            'offlineQueuePath' => $this->spoolDir,
        ]);

        $spool = $sdk->getSpool();
        $this->assertNotNull($spool);

        // Even if something tried to spool a session envelope, it is rejected.
        $spool->persist('/ingest/v1/sessions/start', ['sessionId' => 'x']);
        $spool->persist('/ingest/v1/sessions/end', ['sessionId' => 'x', 'status' => 'ok']);
        $this->assertSame(0, $this->spoolCount());

        $sdk->shutdown();
        // No session traffic is generated under the unit-test runtime guard, so
        // the spool stays empty regardless.
        $this->assertSame(0, $this->spoolCount());
        AllStak::reset();
    }

    // ─── opt-out flag defaults / config plumbing ─────────────────────

    public function testOfflineQueueDefaultsOn(): void
    {
        $options = new Options(['apiKey' => 'allstak_live_test', 'host' => $this->host]);
        $this->assertTrue($options->enableOfflineQueue);
        $this->assertNotSame('', $options->offlineQueuePath);
        $this->assertSame(100, $options->offlineQueueMaxEvents);
    }

    public function testOfflineQueueConfigOverrides(): void
    {
        $options = new Options([
            'apiKey' => 'allstak_live_test',
            'host' => $this->host,
            'offlineQueuePath' => '/tmp/custom-spool',
            'offlineQueueMaxEvents' => 30,
            'offlineQueueMaxBytes' => 1024,
            'offlineQueueMaxAgeSeconds' => 3600,
        ]);
        $this->assertSame('/tmp/custom-spool', $options->offlineQueuePath);
        $this->assertSame(30, $options->offlineQueueMaxEvents);
        $this->assertSame(1024, $options->offlineQueueMaxBytes);
        $this->assertSame(3600, $options->offlineQueueMaxAgeSeconds);
    }
}
