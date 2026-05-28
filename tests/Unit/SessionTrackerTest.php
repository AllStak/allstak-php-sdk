<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Config\Options;
use AllStak\SdkLogger;
use AllStak\Session\SessionTracker;
use AllStak\Tests\Support\MockServerTestCase;
use AllStak\Transport\HttpClient;

/**
 * Release-health session lifecycle: start payload shape, end payload shape +
 * status transitions (ok -> errored -> crashed), and the
 * enableAutoSessionTracking=false opt-out.
 *
 * The {@see SessionTracker} is exercised directly (rather than via AllStak::init)
 * because AllStak skips session tracking under a unit-test runtime — see
 * {@see AllStak::isLikelyTestRuntime()}. The tracker reuses the same HttpClient
 * transport AllStak wires at init, so the wire payloads asserted here match
 * production exactly.
 */
final class SessionTrackerTest extends MockServerTestCase
{
    private function makeTracker(array $extra = []): SessionTracker
    {
        $options = new Options(array_merge([
            'apiKey'      => 'allstak_live_test',
            'host'        => $this->host,
            'environment' => 'test',
            'release'     => '2.4.0',
            'maxRetries'  => 1,
            'connectTimeoutMs' => 1000,
            'totalTimeoutMs'   => 2000,
        ], $extra));

        return new SessionTracker($options, new HttpClient($options, new SdkLogger(false)), new SdkLogger(false));
    }

    // ─── Start payload shape ─────────────────────────────────────────

    public function testStartPostsSessionStartEnvelope(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start('usr_42');

        $starts = $this->requestsForPath('/ingest/v1/sessions/start');
        $this->assertCount(1, $starts, 'exactly one /sessions/start should be posted');

        $entry = $starts[0];
        $this->assertSame('POST', $entry['method']);
        $this->assertSame('allstak_live_test', $entry['apiKey']);

        $payload = $entry['payload'];
        $this->assertNotEmpty($payload['sessionId']);
        $this->assertSame($tracker->currentSessionId(), $payload['sessionId']);
        $this->assertSame('2.4.0', $payload['release']);
        $this->assertSame('test', $payload['environment']);
        $this->assertSame('usr_42', $payload['userId']);
        $this->assertSame('allstak-php', $payload['sdkName']);
        $this->assertSame(Options::VERSION, $payload['sdkVersion']);
        $this->assertSame('php', $payload['platform']);
    }

    public function testStartIsIdempotent(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();
        $firstId = $tracker->currentSessionId();
        $tracker->start(); // no-op

        $starts = $this->requestsForPath('/ingest/v1/sessions/start');
        $this->assertCount(1, $starts, 'second start() must be a no-op');
        $this->assertSame($firstId, $tracker->currentSessionId());
    }

    public function testReleaseFallsBackToSdkVersionWhenNoRelease(): void
    {
        // No explicit release, no env release, autodetect off -> SDK version.
        $tracker = $this->makeTracker(['release' => '', 'autoDetectRelease' => false]);
        $tracker->start();

        $payload = $this->requestsForPath('/ingest/v1/sessions/start')[0]['payload'];
        $this->assertSame(Options::VERSION, $payload['release']);
    }

    public function testStartFailOpenOnUnreachableHost(): void
    {
        $options = new Options([
            'apiKey'      => 'allstak_live_test',
            'host'        => 'http://localhost:1', // nothing listening
            'environment' => 'test',
            'release'     => '1.0.0',
            'maxRetries'  => 1,
            'connectTimeoutMs' => 300,
            'totalTimeoutMs'   => 600,
        ]);
        $tracker = new SessionTracker($options, new HttpClient($options, new SdkLogger(false)), new SdkLogger(false));

        // Must not throw even though the network call fails.
        $tracker->start();
        $this->assertNotNull($tracker->currentSessionId());
        $this->assertSame(SessionTracker::STATUS_OK, $tracker->status());
    }

    // ─── End payload shape + status transitions ──────────────────────

    public function testEndPostsOkWhenNoErrors(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();
        usleep(20_000); // ensure a non-zero duration
        $tracker->end();

        $ends = $this->requestsForPath('/ingest/v1/sessions/end');
        $this->assertCount(1, $ends);

        $payload = $ends[0]['payload'];
        $this->assertNull($tracker->currentSessionId()); // ended -> null
        $this->assertNotEmpty($payload['sessionId']);
        $this->assertSame(SessionTracker::STATUS_OK, $payload['status']);
        $this->assertIsInt($payload['durationMs']);
        $this->assertGreaterThanOrEqual(0, $payload['durationMs']);
    }

    public function testStatusTransitionOkToErrored(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();
        $this->assertSame(SessionTracker::STATUS_OK, $tracker->status());

        $tracker->recordError();
        $this->assertSame(SessionTracker::STATUS_ERRORED, $tracker->status());

        $tracker->end();
        $payload = $this->requestsForPath('/ingest/v1/sessions/end')[0]['payload'];
        $this->assertSame(SessionTracker::STATUS_ERRORED, $payload['status']);
    }

    public function testStatusTransitionErroredToCrashed(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();

        $tracker->recordError();
        $this->assertSame(SessionTracker::STATUS_ERRORED, $tracker->status());

        $tracker->recordCrash();
        $this->assertSame(SessionTracker::STATUS_CRASHED, $tracker->status());

        $tracker->end();
        $payload = $this->requestsForPath('/ingest/v1/sessions/end')[0]['payload'];
        $this->assertSame(SessionTracker::STATUS_CRASHED, $payload['status']);
    }

    public function testCrashedIsNotDowngradedByLaterError(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();

        $tracker->recordCrash();
        $tracker->recordError(); // must not downgrade crashed -> errored
        $this->assertSame(SessionTracker::STATUS_CRASHED, $tracker->status());

        $tracker->end();
        $payload = $this->requestsForPath('/ingest/v1/sessions/end')[0]['payload'];
        $this->assertSame(SessionTracker::STATUS_CRASHED, $payload['status']);
    }

    public function testEndIsIdempotent(): void
    {
        $tracker = $this->makeTracker();
        $tracker->start();
        $tracker->end();
        $tracker->end(); // no-op

        $this->assertCount(1, $this->requestsForPath('/ingest/v1/sessions/end'));
    }

    public function testEndBeforeStartIsNoOp(): void
    {
        $tracker = $this->makeTracker();
        $tracker->end();
        $this->assertCount(0, $this->requestsForPath('/ingest/v1/sessions/end'));
    }

    // ─── sessionId carried on error events ───────────────────────────

    public function testCurrentSessionIdNullBeforeStartAndAfterEnd(): void
    {
        $tracker = $this->makeTracker();
        $this->assertNull($tracker->currentSessionId());
        $tracker->start();
        $this->assertNotNull($tracker->currentSessionId());
        $tracker->end();
        $this->assertNull($tracker->currentSessionId());
    }

    // ─── enableAutoSessionTracking opt-out ───────────────────────────

    public function testOptOutDefaultsTrue(): void
    {
        $options = new Options(['apiKey' => 'allstak_live_test', 'host' => $this->host]);
        $this->assertTrue($options->enableAutoSessionTracking);
    }

    public function testOptOutCanBeDisabled(): void
    {
        $options = new Options([
            'apiKey' => 'allstak_live_test',
            'host'   => $this->host,
            'enableAutoSessionTracking' => false,
        ]);
        $this->assertFalse($options->enableAutoSessionTracking);
    }

    public function testAllStakInitDoesNotStartSessionUnderTestRuntime(): void
    {
        // AllStak::init must skip session tracking under the unit-test runtime
        // guard, so booting the SDK emits no /sessions/start traffic.
        $sdk = $this->initSdk();
        $sdk->flush();

        $this->assertCount(0, $this->requestsForPath('/ingest/v1/sessions/start'));
        $this->assertNull($sdk->currentSessionId());

        AllStak::reset();
    }
}
