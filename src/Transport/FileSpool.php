<?php

declare(strict_types=1);

namespace AllStak\Transport;

use AllStak\Privacy\Sanitizer;
use AllStak\SdkLogger;

/**
 * Persistent on-disk spool for un-sent telemetry — an offline /
 * cached-envelope store that survives a process restart or network outage.
 *
 * When the in-memory {@see \AllStak\Buffer\RingBuffer} cannot be delivered at
 * shutdown (network outage, retries exhausted, server unreachable) the payload
 * is written here, one JSON file per envelope, instead of being dropped. On the
 * next request/process init {@see drain()} replays each file through the normal
 * retry transport and removes it only once the server accepts it (2xx) or it is
 * permanently undeliverable (a non-429 4xx). Everything is fail-open: a
 * read-only / sandboxed / serverless filesystem degrades silently to the
 * existing in-memory behavior and NEVER throws or blocks init/capture.
 *
 * Invariants:
 *   - Payloads are PII-scrubbed via {@see Sanitizer::maskMetadata()} BEFORE they
 *     touch disk — a secret must never be persisted.
 *   - Session lifecycle envelopes (/sessions/start, /sessions/end) are
 *     best-effort live-only and are never spooled — a replayed stale session
 *     would skew release-health durations.
 *   - The store is bounded by count, total bytes, and max age. When full the
 *     OLDEST entry is evicted first (drop-oldest), so the store never grows
 *     unbounded.
 *
 * On-disk format: each spooled envelope is a file
 *   {dir}/{microtime-prefix}-{rand}.aspool.json
 * containing {"path": "<ingest path>", "payload": {...}, "ts": <unix-ms>}.
 * The timestamp lives both in the filename prefix (cheap age/order sort) and in
 * the JSON body (authoritative).
 */
final class FileSpool
{
    /** File extension marking a spooled envelope. Used to glob the store. */
    public const EXT = '.aspool.json';

    /** Ingest paths that must never be spooled (live-only best-effort). */
    private const NON_PERSISTABLE_PATHS = [
        '/ingest/v1/sessions/start',
        '/ingest/v1/sessions/end',
    ];

    private SdkLogger $logger;
    private string $dir;
    private int $maxEvents;
    private int $maxBytes;
    private int $maxAgeSeconds;

    /**
     * Mirrors {@see \AllStak\Config\Options::$sendDefaultPii}. The spool scrubs
     * each payload before it touches disk; this flag controls whether the
     * email/IPv4 value scrubbers run (parity with the live transport chokepoint
     * so a secret never reaches disk via a weaker pass than the wire).
     */
    private bool $sendDefaultPii;

    /**
     * Whether the spool directory is usable (exists and is writable). Resolved
     * once at construction; when false every operation no-ops so a read-only or
     * sandboxed filesystem degrades to pure in-memory behavior.
     */
    private bool $available;
    private int $droppedCount = 0;

    public function __construct(
        SdkLogger $logger,
        string $dir,
        int $maxEvents = 100,
        int $maxBytes = 5_242_880, // 5 MiB
        int $maxAgeSeconds = 172_800, // 48h
        bool $sendDefaultPii = false
    ) {
        $this->logger = $logger;
        $this->dir = rtrim($dir, '/');
        $this->maxEvents = max(1, $maxEvents);
        $this->maxBytes = max(1, $maxBytes);
        $this->maxAgeSeconds = max(0, $maxAgeSeconds);
        $this->sendDefaultPii = $sendDefaultPii;
        $this->available = $this->ensureDir();
    }

    /** True when the store can actually be read/written on this filesystem. */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** Whether a given ingest path may be persisted. Session calls never are. */
    public static function isPersistable(string $path): bool
    {
        return !in_array($path, self::NON_PERSISTABLE_PATHS, true);
    }

    /**
     * Persist one un-sent envelope. The payload is PII-scrubbed before it is
     * written. Session lifecycle paths are silently skipped. Fully fail-open:
     * any I/O or encode failure is swallowed (the event is simply lost, exactly
     * as before this feature existed) and never propagates to the caller.
     *
     * @param string $path    Ingest path the envelope was destined for.
     * @param array  $payload Wire payload (will be scrubbed here before write).
     * @return bool True when the envelope was written to disk.
     */
    public function persist(string $path, array $payload): bool
    {
        if (!$this->available || !self::isPersistable($path)) {
            return false;
        }

        try {
            // SCRUB BEFORE PERSIST. The live transport scrubs at its own
            // chokepoint, but the spool write happens earlier, so we must run
            // the sanitizer here too — a secret must never reach disk.
            $scrubbed = Sanitizer::maskMetadata($payload, $this->sendDefaultPii);

            $json = json_encode(
                ['path' => $path, 'payload' => $scrubbed, 'ts' => (int) round(microtime(true) * 1000)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($json === false) {
                $this->droppedCount++;
                return false; // un-encodable payload — drop, never throw
            }

            // Enforce bounds BEFORE writing so the new entry always fits.
            $this->evictForRoom(strlen($json));

            $file = $this->dir . '/' . $this->nextName();
            // LOCK_EX so a concurrent PHP-FPM worker never reads a half file.
            if (@file_put_contents($file, $json, LOCK_EX) === false) {
                $this->logger->debug('AllStak SDK: spool write failed', ['file' => $file]);
                $this->droppedCount++;
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            // Persistence is best-effort; losing one event is acceptable, a
            // thrown exception during shutdown drain is not.
            $this->logger->debug('AllStak SDK: spool persist failed', ['error' => $e->getMessage()]);
            $this->droppedCount++;
            return false;
        }
    }

    /**
     * Replay every spooled envelope through {@see $send}, oldest first. An entry
     * is removed only when {@see $send} reports the envelope is settled — i.e.
     * accepted (2xx) or permanently undeliverable (a non-429 4xx). Retryable
     * outcomes (network error, 5xx, 429) leave the file on disk for the next
     * init. Expired files (older than maxAgeSeconds) are pruned without sending.
     *
     * The callback receives ($path, $payload) and must return true to delete
     * the entry, false to keep it. Fully fail-open: directory or per-file
     * failures are swallowed and never block init.
     *
     * @param callable(string,array):bool $send
     * @return int Number of entries removed (sent or pruned).
     */
    public function drain(callable $send): int
    {
        if (!$this->available) {
            return 0;
        }

        $removed = 0;
        try {
            $files = $this->sortedFiles(); // oldest first
            $cutoffMs = $this->maxAgeSeconds > 0
                ? ((int) round(microtime(true) * 1000)) - ($this->maxAgeSeconds * 1000)
                : null;

            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    continue; // vanished (another worker drained it) — skip
                }
                $entry = json_decode($raw, true);
                if (!is_array($entry) || !isset($entry['path']) || !is_array($entry['payload'] ?? null)) {
                    // Corrupt entry — prune it so it can't wedge the queue.
                    @unlink($file);
                    $removed++;
                    continue;
                }

                // Drop expired entries without spending a network round-trip.
                if ($cutoffMs !== null && (int) ($entry['ts'] ?? 0) < $cutoffMs) {
                    @unlink($file);
                    $removed++;
                    continue;
                }

                $settled = false;
                try {
                    $settled = (bool) $send((string) $entry['path'], $entry['payload']);
                } catch (\Throwable $e) {
                    // Treat a thrown transport as retryable — keep the file.
                    $this->logger->debug('AllStak SDK: spool replay threw', ['error' => $e->getMessage()]);
                }

                if ($settled) {
                    @unlink($file);
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: spool drain failed', ['error' => $e->getMessage()]);
        }

        return $removed;
    }

    /** Number of envelopes currently on disk. 0 when the store is unavailable. */
    public function count(): int
    {
        if (!$this->available) {
            return 0;
        }
        $files = @glob($this->dir . '/*' . self::EXT) ?: [];
        return count($files);
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    // ─── Internal ────────────────────────────────────────────────────

    /**
     * Make a unique, time-sortable filename. The integer-microsecond prefix
     * keeps lexical order == chronological order for the common case; a random
     * suffix avoids collisions when two events are spooled in the same µs.
     */
    private function nextName(): string
    {
        $prefix = sprintf('%016d', (int) round(microtime(true) * 1_000_000));
        return $prefix . '-' . bin2hex(random_bytes(6)) . self::EXT;
    }

    /**
     * Evict OLDEST entries until both the count cap and the byte cap leave room
     * for an incoming entry of $incomingBytes. Drop-oldest, never newest.
     */
    private function evictForRoom(int $incomingBytes): void
    {
        $files = $this->sortedFiles(); // oldest first

        // Count cap: make space for one more.
        while (count($files) >= $this->maxEvents) {
            $victim = array_shift($files);
            if ($victim === null) {
                break;
            }
            @unlink($victim);
            $this->droppedCount++;
            $this->logger->debug('AllStak SDK: spool full (count) — dropping oldest');
        }

        // Byte cap: incoming + existing must fit under maxBytes.
        $usedBytes = 0;
        foreach ($files as $f) {
            $usedBytes += (int) (@filesize($f) ?: 0);
        }
        while ($files !== [] && ($usedBytes + $incomingBytes) > $this->maxBytes) {
            $victim = array_shift($files);
            if ($victim === null) {
                break;
            }
            $usedBytes -= (int) (@filesize($victim) ?: 0);
            @unlink($victim);
            $this->droppedCount++;
            $this->logger->debug('AllStak SDK: spool full (bytes) — dropping oldest');
        }
    }

    /**
     * All spool files sorted oldest-first. Sort by the integer prefix in the
     * filename (cheap, no stat) and fall back to the name for ties.
     *
     * @return list<string> absolute file paths
     */
    private function sortedFiles(): array
    {
        $files = @glob($this->dir . '/*' . self::EXT) ?: [];
        sort($files, SORT_STRING); // lexical == chronological given the prefix
        return $files;
    }

    /**
     * Ensure the spool directory exists and is writable. Returns false (and
     * never throws) on any failure so the caller degrades to in-memory.
     */
    private function ensureDir(): bool
    {
        try {
            if ($this->dir === '') {
                return false;
            }
            if (!is_dir($this->dir)) {
                if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                    return false;
                }
            }
            return is_writable($this->dir);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
