<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\AllStak;
use AllStak\Privacy\Sanitizer;

/**
 * Utility class for instrumenting outbound HTTP calls made with cURL.
 * Wraps a cURL request and records telemetry to the SDK.
 */
final class HttpMonitor
{
    private AllStak $sdk;

    public function __construct(AllStak $sdk)
    {
        $this->sdk = $sdk;
    }

    /**
     * Execute a cURL request and record HTTP telemetry.
     *
     * @param \CurlHandle $ch A prepared cURL handle
     * @param string $direction 'outbound' (default) or 'inbound'
     * @return string|bool The cURL response body (or false on failure)
     */
    public function execute(\CurlHandle $ch, string $direction = 'outbound'): string|bool
    {
        // Ensure we get the response body back
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $endTime = microtime(true);

        $durationMs = (int) round(($endTime - $startTime) * 1000);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'unknown';
        $path = Sanitizer::stripQueryParams($parsed['path'] ?? '/');
        $method = strtoupper(curl_getinfo($ch, CURLINFO_EFFECTIVE_METHOD) ?: 'GET');

        $requestSize = (int) curl_getinfo($ch, CURLINFO_REQUEST_SIZE);
        $responseSize = is_string($response) ? strlen($response) : 0;

        $this->sdk->captureHttpRequest([
            'direction' => $direction,
            'method' => $method,
            'host' => $host,
            'path' => $path,
            'statusCode' => $statusCode,
            'durationMs' => $durationMs,
            'requestSize' => $requestSize,
            'responseSize' => $responseSize,
        ]);

        return $response;
    }

    /**
     * Record an inbound request (useful in middleware).
     */
    public function recordInbound(
        string $method,
        string $host,
        string $path,
        int $statusCode,
        int $durationMs,
        int $requestSize = 0,
        int $responseSize = 0,
        ?string $userId = null,
    ): void {
        $data = [
            'direction' => 'inbound',
            'method' => strtoupper($method),
            'host' => $host,
            'path' => Sanitizer::stripQueryParams($path),
            'statusCode' => $statusCode,
            'durationMs' => max(0, $durationMs),
            'requestSize' => $requestSize,
            'responseSize' => $responseSize,
        ];

        if ($userId !== null) {
            $data['userId'] = $userId;
        }

        $this->sdk->captureHttpRequest($data);
    }
}
