<?php

declare(strict_types=1);

namespace AllStak\Transport;

use AllStak\Config\Options;
use AllStak\Privacy\Sanitizer;
use AllStak\SdkLogger;

final class HttpClient
{
    private Options $options;
    private SdkLogger $logger;

    public function __construct(Options $options, SdkLogger $logger)
    {
        $this->options = $options;
        $this->logger = $logger;
    }

    /**
     * Mandated wire User-Agent: allstak-<sdk>/<version> (e.g. allstak-php/1.4.0,
     * or allstak-symfony/0.1.0 when this core powers a framework bundle).
     *
     * Derived from the active SDK identity on the {@see Options} instance so a
     * framework SDK that wraps this core (passing its own sdkName/sdkVersion)
     * is attributed under its own name on the wire — matching the sdkName /
     * sdkVersion it already sends in the JSON body. Falls back to the core's
     * own constants when the instance left them blank.
     *
     * Sent on every ingest and management request so the backend can attribute
     * traffic to the SDK and version that produced it.
     */
    private function userAgent(): string
    {
        $name = $this->options->sdkName !== '' ? $this->options->sdkName : Options::SDK_NAME;
        $version = $this->options->sdkVersion !== '' ? $this->options->sdkVersion : Options::VERSION;

        return $name . '/' . $version;
    }

    /**
     * Send a POST request to an ingestion endpoint.
     *
     * @return array{statusCode: int, body: array|null, error: string|null, retryAfter: string|null}
     */
    public function postIngest(string $path, array $payload): array
    {
        $url = $this->options->host . $path;
        $headers = [
            'Content-Type: application/json',
            'X-AllStak-Key: ' . $this->options->apiKey,
            'User-Agent: ' . $this->userAgent(),
        ];

        return $this->doPost($url, $headers, $payload);
    }

    /**
     * Send a GET request to management API (for feature flags).
     *
     * @return array{statusCode: int, body: array|null, error: string|null, retryAfter: string|null}
     */
    public function getManagement(string $path, array $queryParams = []): array
    {
        $url = $this->options->host . $path;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent(),
        ];
        if ($this->options->bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->options->bearerToken;
        }

        return $this->doGet($url, $headers);
    }

    private function doPost(string $url, array $headers, array $payload): array
    {
        // Scrub the full wire payload before serialization. One chokepoint
        // protects every telemetry type (errors, logs, http, db, traces).
        // Pure (no caller mutation), fail-closed for this event on sanitizer exception.
        try {
            $payload = Sanitizer::maskMetadata($payload, $this->options->sendDefaultPii);
        } catch (\Throwable $sanErr) {
            $this->logger->debug('Sanitizer failed; dropping payload', ['error' => $sanErr->getMessage()]);
            return ['statusCode' => 0, 'body' => null, 'error' => 'Sanitizer failed; payload dropped', 'retryAfter' => null];
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->logger->debug("POST {$url}", ['size' => strlen($json)]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->options->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->options->totalTimeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $this->execute($ch);
    }

    private function doGet(string $url, array $headers): array
    {
        $this->logger->debug("GET {$url}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->options->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->options->totalTimeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $this->execute($ch);
    }

    private function execute(\CurlHandle $ch): array
    {
        // Capture the Retry-After response header (case-insensitive) so the
        // retry handler can honor server-directed backoff on 429/503. We parse
        // headers inline via CURLOPT_HEADERFUNCTION to avoid prepending the raw
        // header block onto the body (which CURLOPT_HEADER would do).
        $retryAfter = null;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($_ch, string $line) use (&$retryAfter): int {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                if ($name === 'retry-after') {
                    $retryAfter = trim(substr($line, $colon + 1));
                }
            }
            return strlen($line);
        });

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            $this->logger->debug("Request failed: {$error}");
            return ['statusCode' => 0, 'body' => null, 'error' => $error ?: 'Unknown curl error', 'retryAfter' => $retryAfter];
        }

        $decoded = json_decode((string) $body, true);
        $this->logger->debug("Response {$statusCode}", ['body' => $decoded]);

        return ['statusCode' => $statusCode, 'body' => $decoded, 'error' => null, 'retryAfter' => $retryAfter];
    }
}
