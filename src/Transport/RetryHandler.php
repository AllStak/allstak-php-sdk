<?php

declare(strict_types=1);

namespace AllStak\Transport;

use AllStak\SdkLogger;

final class RetryHandler
{
    private const NON_RETRYABLE = [400, 401, 403, 422];
    private const BACKOFF_BASE_MS = [0, 1000, 2000, 4000, 8000]; // attempt 1-5
    private const JITTER_MAX_MS = 500;

    private HttpClient $client;
    private SdkLogger $logger;
    private int $maxRetries;

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
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delayMs = self::BACKOFF_BASE_MS[$attempt] ?? 8000;
                $jitter = random_int(0, self::JITTER_MAX_MS);
                $totalDelay = ($delayMs + $jitter) * 1000; // microseconds
                $this->logger->debug("Retry attempt {$attempt}, sleeping {$delayMs}+{$jitter}ms");
                usleep($totalDelay);
            }

            $result = $this->client->postIngest($path, $payload);
            $status = $result['statusCode'];

            // Success
            if ($status >= 200 && $status < 300) {
                return $result;
            }

            // 401 — disable SDK entirely
            if ($status === 401) {
                $this->logger->warning('AllStak SDK: invalid API key — disabling SDK');
                if ($this->onAuthFailure !== null) {
                    ($this->onAuthFailure)();
                }
                return $result;
            }

            // Non-retryable client errors
            if (in_array($status, self::NON_RETRYABLE, true)) {
                $this->logger->debug("Non-retryable status {$status} — dropping event");
                return $result;
            }

            // 429 — respect Retry-After if present
            if ($status === 429) {
                $this->logger->debug('Rate limited (429)');
                // Continue retry loop with backoff
                continue;
            }

            // 5xx or network error — retry
            if ($status >= 500 || $status === 0) {
                $this->logger->debug("Retryable failure: status={$status}, error={$result['error']}");
                continue;
            }

            // Any other status — don't retry
            return $result;
        }

        $this->logger->debug("Max retries ({$this->maxRetries}) exhausted — discarding event");
        return ['statusCode' => 0, 'body' => null, 'error' => 'Max retries exhausted'];
    }
}
