<?php

declare(strict_types=1);

namespace AllStak\Transport;

use AllStak\SdkLogger;

final class RetryHandler
{
    private const NON_RETRYABLE = [400, 401, 403, 422];
    private const BACKOFF_BASE_MS = [0, 1000, 2000, 4000, 8000]; // attempt 1-5
    private const JITTER_MAX_MS = 500;

    /** Upper bound (seconds) we will ever wait for a server-directed Retry-After. */
    public const RETRY_AFTER_MAX_SECONDS = 300.0;

    private HttpClient $client;
    private SdkLogger $logger;
    private int $maxRetries;
    private int $sentCount = 0;
    private int $failedCount = 0;
    private int $droppedCount = 0;
    private int $retryAttemptCount = 0;
    private int $rateLimitedCount = 0;

    /** @var callable|null Called on 401 to disable SDK */
    private $onAuthFailure;

    public function __construct(HttpClient $client, SdkLogger $logger, int $maxRetries = 5, ?callable $onAuthFailure = null)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->onAuthFailure = $onAuthFailure;
    }

    /**
     * Send with retry logic per SDK guidelines.
     *
     * @return array{statusCode: int, body: array|null, error: string|null}
     */
    public function sendWithRetry(string $path, array $payload): array
    {
        // Seconds the server told us to wait via Retry-After on the *previous*
        // attempt. Overrides exponential backoff for the next sleep when present.
        $serverDirectedDelaySec = 0.0;

        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->retryAttemptCount++;
                if ($serverDirectedDelaySec > 0.0) {
                    // Honor the server's Retry-After. No extra jitter — the
                    // server already told us exactly when it's willing to talk.
                    $totalDelay = (int) round($serverDirectedDelaySec * 1_000_000); // microseconds
                    $this->logger->debug("Retry attempt {$attempt}, honoring Retry-After {$serverDirectedDelaySec}s");
                } else {
                    $delayMs = self::BACKOFF_BASE_MS[$attempt] ?? 8000;
                    $jitter = random_int(0, self::JITTER_MAX_MS);
                    $totalDelay = ($delayMs + $jitter) * 1000; // microseconds
                    $this->logger->debug("Retry attempt {$attempt}, sleeping {$delayMs}+{$jitter}ms");
                }
                usleep($totalDelay);
            }

            $result = $this->client->postIngest($path, $payload);
            $status = $result['statusCode'];
            $serverDirectedDelaySec = 0.0; // reset; recompute from this response

            // Success
            if ($status >= 200 && $status < 300) {
                $this->sentCount++;
                return $result;
            }

            // 401 — disable SDK entirely
            if ($status === 401) {
                $this->logger->warning('AllStak SDK: invalid API key — disabling SDK');
                if ($this->onAuthFailure !== null) {
                    ($this->onAuthFailure)();
                }
                $this->failedCount++;
                $this->droppedCount++;
                return $result;
            }

            // Non-retryable client errors
            if (in_array($status, self::NON_RETRYABLE, true)) {
                $this->logger->debug("Non-retryable status {$status} — dropping event");
                $this->droppedCount++;
                return $result;
            }

            // 429 / 503 — read the real Retry-After header. Integer seconds or
            // an HTTP-date both parse; absent/invalid falls back to backoff (0.0).
            if ($status === 429 || $status === 503) {
                if ($status === 429) {
                    $this->rateLimitedCount++;
                }
                $serverDirectedDelaySec = self::parseRetryAfter($result['retryAfter'] ?? null);
                if ($serverDirectedDelaySec > 0.0) {
                    $this->logger->debug("Status {$status}: Retry-After={$serverDirectedDelaySec}s");
                } else {
                    $this->logger->debug("Status {$status}: no usable Retry-After, using backoff");
                }
                continue;
            }

            // 5xx or network error — retry with backoff
            if ($status >= 500 || $status === 0) {
                $this->logger->debug("Retryable failure: status={$status}, error={$result['error']}");
                continue;
            }

            // Any other status — don't retry
            return $result;
        }

        $this->logger->debug("Max retries ({$this->maxRetries}) exhausted — discarding event");
        $this->failedCount++;
        return ['statusCode' => 0, 'body' => null, 'error' => 'Max retries exhausted', 'retryAfter' => null];
    }

    /** @return array{sent:int,failed:int,dropped:int,retryAttempts:int,rateLimited:int,compressedPayloads:int,uncompressedPayloads:int,compressionBytesSaved:int} */
    public function diagnostics(): array
    {
        $clientDiagnostics = method_exists($this->client, 'diagnostics') ? $this->client->diagnostics() : [];
        return [
            'sent' => $this->sentCount,
            'failed' => $this->failedCount,
            'dropped' => $this->droppedCount,
            'retryAttempts' => $this->retryAttemptCount,
            'rateLimited' => $this->rateLimitedCount,
            'compressedPayloads' => (int) ($clientDiagnostics['compressedPayloads'] ?? 0),
            'uncompressedPayloads' => (int) ($clientDiagnostics['uncompressedPayloads'] ?? 0),
            'compressionBytesSaved' => (int) ($clientDiagnostics['compressionBytesSaved'] ?? 0),
        ];
    }

    /**
     * Parse an HTTP `Retry-After` header value into a delay in seconds.
     *
     * Per RFC 7231 §7.1.3 the value is either a non-negative integer number of
     * seconds (delta-seconds) or an HTTP-date. Anything absent, empty, or
     * unparseable returns 0.0 so the caller falls back to exponential backoff.
     * The result is clamped to {@see self::RETRY_AFTER_MAX_SECONDS}; a date in
     * the past returns 0.0.
     *
     * Pure: no I/O, no sleeping. `$now` (unix seconds) is injectable for tests.
     */
    public static function parseRetryAfter(?string $header, ?int $now = null): float
    {
        if ($header === null) {
            return 0.0;
        }
        $header = trim($header);
        if ($header === '') {
            return 0.0;
        }

        // delta-seconds: a bare non-negative integer.
        if (preg_match('/^\d+$/', $header) === 1) {
            $seconds = (float) $header;
            return min($seconds, self::RETRY_AFTER_MAX_SECONDS);
        }

        // HTTP-date: compute delta from now. strtotime() is lax (it will happily
        // read "12abc" as a time-of-day today), so first require a 4-digit year —
        // every RFC 7231 HTTP-date form (IMF-fixdate, RFC 850, asctime) contains
        // one. This rejects partial-numeric garbage that isn't a real date.
        if (preg_match('/\d{4}/', $header) !== 1) {
            return 0.0;
        }
        $ts = strtotime($header);
        if ($ts === false) {
            return 0.0;
        }
        $now ??= time();
        $delta = (float) ($ts - $now);
        if ($delta <= 0.0) {
            return 0.0;
        }
        return min($delta, self::RETRY_AFTER_MAX_SECONDS);
    }
}
