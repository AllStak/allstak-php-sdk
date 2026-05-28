<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\SdkLogger;
use AllStak\Transport\FileSpool;
use PHPUnit\Framework\TestCase;

/**
 * Offline / persistent event queue at the FileSpool layer:
 *   - persist writes a scrubbed envelope to disk
 *   - scrub-before-persist (no secret value hits disk)
 *   - cap/eviction drops OLDEST first (count + bytes)
 *   - session lifecycle calls are NOT persisted
 *   - drain replays oldest-first and removes only settled entries
 *   - retryable outcomes (network/5xx/429) keep the file for next init
 *   - expired entries are pruned without sending
 *   - graceful no-op when the store directory is unavailable/unwritable
 *
 * The spool is exercised directly with a throwaway temp dir so these tests need
 * no network and assert exactly what reaches disk.
 */
final class FileSpoolTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/allstak-spool-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function makeSpool(int $maxEvents = 100, int $maxBytes = 5_242_880, int $maxAge = 172_800): FileSpool
    {
        return new FileSpool(new SdkLogger(false), $this->dir, $maxEvents, $maxBytes, $maxAge);
    }

    // ─── persist + drain round-trip ──────────────────────────────────

    public function testPersistWritesEnvelopeToDisk(): void
    {
        $spool = $this->makeSpool();
        $this->assertTrue($spool->isAvailable());

        $spool->persist('/ingest/v1/logs', ['level' => 'error', 'message' => 'boom']);
        $this->assertSame(1, $spool->count());

        $seen = [];
        $removed = $spool->drain(function (string $path, array $payload) use (&$seen): bool {
            $seen[] = ['path' => $path, 'payload' => $payload];
            return true; // accepted (2xx)
        });

        $this->assertSame(1, $removed);
        $this->assertSame(0, $spool->count(), 'settled entry must be removed');
        $this->assertCount(1, $seen);
        $this->assertSame('/ingest/v1/logs', $seen[0]['path']);
        $this->assertSame('boom', $seen[0]['payload']['message']);
    }

    // ─── scrub before persist ────────────────────────────────────────

    public function testScrubsSecretsBeforeWritingToDisk(): void
    {
        $spool = $this->makeSpool();
        $secret = 'sk_live_SUPERSECRET_VALUE';
        $spool->persist('/ingest/v1/logs', [
            'message' => 'login',
            'metadata' => ['password' => $secret, 'authorization' => 'Bearer ' . $secret, 'safe' => 'keep-me'],
        ]);

        // Inspect the raw bytes on disk — the secret must never appear.
        $files = glob($this->dir . '/*' . FileSpool::EXT) ?: [];
        $this->assertCount(1, $files);
        $raw = (string) file_get_contents($files[0]);
        $this->assertStringNotContainsString($secret, $raw, 'secret must not be persisted');
        $this->assertStringContainsString('[REDACTED]', $raw);
        $this->assertStringContainsString('keep-me', $raw, 'non-sensitive data is preserved');
    }

    // ─── session calls are never persisted ───────────────────────────

    public function testSessionLifecycleCallsAreNotPersisted(): void
    {
        $spool = $this->makeSpool();
        $spool->persist('/ingest/v1/sessions/start', ['sessionId' => 'abc']);
        $spool->persist('/ingest/v1/sessions/end', ['sessionId' => 'abc', 'status' => 'ok']);
        $spool->persist('/ingest/v1/logs', ['message' => 'kept']);

        $this->assertSame(1, $spool->count(), 'only the log envelope should be spooled');
        $this->assertFalse(FileSpool::isPersistable('/ingest/v1/sessions/start'));
        $this->assertFalse(FileSpool::isPersistable('/ingest/v1/sessions/end'));
        $this->assertTrue(FileSpool::isPersistable('/ingest/v1/errors'));
    }

    // ─── bounded: drop OLDEST on count cap ───────────────────────────

    public function testCountCapDropsOldest(): void
    {
        $spool = $this->makeSpool(maxEvents: 3);
        for ($i = 1; $i <= 5; $i++) {
            $spool->persist('/ingest/v1/logs', ['n' => $i]);
            usleep(2000); // keep filename timestamps strictly increasing
        }

        $this->assertSame(3, $spool->count(), 'count must be capped at maxEvents');

        $ns = [];
        $spool->drain(function (string $path, array $payload) use (&$ns): bool {
            $ns[] = $payload['n'];
            return true;
        });

        // Oldest (1,2) dropped; newest (3,4,5) retained.
        $this->assertSame([3, 4, 5], $ns);
    }

    // ─── bounded: drop OLDEST on byte cap ────────────────────────────

    public function testByteCapDropsOldest(): void
    {
        // Each envelope is well over 200 bytes; cap at ~600 bytes keeps ~2.
        $spool = $this->makeSpool(maxEvents: 1000, maxBytes: 600);
        for ($i = 1; $i <= 6; $i++) {
            $spool->persist('/ingest/v1/logs', ['n' => $i, 'pad' => str_repeat('x', 200)]);
            usleep(2000);
        }

        $this->assertLessThanOrEqual(3, $spool->count());
        $this->assertGreaterThanOrEqual(1, $spool->count());

        $ns = [];
        $spool->drain(function (string $path, array $payload) use (&$ns): bool {
            $ns[] = $payload['n'];
            return true;
        });

        // Whatever survived must be the NEWEST contiguous run ending at 6.
        $this->assertNotEmpty($ns);
        $this->assertSame(6, end($ns), 'newest entry must survive byte-cap eviction');
        $sorted = $ns;
        sort($sorted);
        $this->assertSame($sorted, $ns, 'survivors stay in chronological order');
    }

    // ─── retryable outcomes keep the file ────────────────────────────

    public function testRetryableOutcomeKeepsEntryOnDisk(): void
    {
        $spool = $this->makeSpool();
        $spool->persist('/ingest/v1/logs', ['message' => 'retry-me']);

        $removed = $spool->drain(fn(string $p, array $pl): bool => false); // not settled
        $this->assertSame(0, $removed);
        $this->assertSame(1, $spool->count(), 'unsettled entry stays for next init');

        // Next init succeeds → removed.
        $removed = $spool->drain(fn(string $p, array $pl): bool => true);
        $this->assertSame(1, $removed);
        $this->assertSame(0, $spool->count());
    }

    public function testDrainSwallowsCallbackException(): void
    {
        $spool = $this->makeSpool();
        $spool->persist('/ingest/v1/logs', ['message' => 'x']);

        // A throwing transport is treated as retryable — file is kept, no throw.
        $removed = $spool->drain(function (): bool {
            throw new \RuntimeException('network down');
        });
        $this->assertSame(0, $removed);
        $this->assertSame(1, $spool->count());
    }

    // ─── expiry pruning ──────────────────────────────────────────────

    public function testExpiredEntriesArePrunedWithoutSending(): void
    {
        // maxAge 0 disables expiry; use a tiny positive age and a backdated file.
        $spool = $this->makeSpool(maxAge: 1);
        $spool->persist('/ingest/v1/logs', ['message' => 'stale']);

        // Rewrite the entry's ts to two hours ago so it is expired.
        $files = glob($this->dir . '/*' . FileSpool::EXT) ?: [];
        $this->assertCount(1, $files);
        $entry = json_decode((string) file_get_contents($files[0]), true);
        $entry['ts'] = ((int) round(microtime(true) * 1000)) - (7_200 * 1000);
        file_put_contents($files[0], json_encode($entry));

        $sent = 0;
        $removed = $spool->drain(function () use (&$sent): bool {
            $sent++;
            return true;
        });

        $this->assertSame(0, $sent, 'expired entry must not be sent');
        $this->assertSame(1, $removed, 'expired entry is pruned');
        $this->assertSame(0, $spool->count());
    }

    // ─── graceful no-op when store unavailable ───────────────────────

    public function testUnavailableWhenDirEmpty(): void
    {
        $spool = new FileSpool(new SdkLogger(false), '', 100, 5_242_880, 172_800);
        $this->assertFalse($spool->isAvailable());

        // All operations must be safe no-ops.
        $spool->persist('/ingest/v1/logs', ['message' => 'x']);
        $this->assertSame(0, $spool->count());
        $this->assertSame(0, $spool->drain(fn(): bool => true));
    }

    public function testUnavailableWhenDirUnwritable(): void
    {
        // A path under a read-only parent: /dev/null is a file, so a child dir
        // can never be created under it. ensureDir() must fail-open to false.
        $spool = new FileSpool(new SdkLogger(false), '/dev/null/allstak-spool', 100, 5_242_880, 172_800);
        $this->assertFalse($spool->isAvailable());
        $spool->persist('/ingest/v1/logs', ['message' => 'x']); // no throw
        $this->assertSame(0, $spool->count());
    }
}
