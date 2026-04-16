<?php

declare(strict_types=1);

namespace AllStak\Config;

final class Options
{
    /**
     * Single, static AllStak ingest host. Not customer-configurable on purpose:
     * customers should never have to know which URL their events go to. To point
     * the SDK at a different deployment (e.g. self-hosted), change this constant
     * in one place.
     */
    public const INGEST_HOST = 'https://api.allstak.io';

    /** SDK version. Surfaced in the User-Agent header sent to the ingest backend. */
    public const VERSION = '1.0.0';

    public readonly string $apiKey;
    public readonly string $host;
    public readonly string $environment;
    public readonly string $release;
    public readonly int $flushIntervalMs;
    public readonly int $bufferSize;
    public readonly bool $debug;

    // Feature flags (management API)
    public readonly string $bearerToken;
    public readonly string $projectId;
    public readonly int $flagCacheTtlSeconds;

    // Auto-breadcrumbs
    public readonly bool $autoBreadcrumbs;
    public readonly int $maxBreadcrumbs;

    // Transport
    public readonly int $connectTimeoutMs;
    public readonly int $readTimeoutMs;
    public readonly int $totalTimeoutMs;
    public readonly int $maxRetries;

    public function __construct(array $config)
    {
        if (empty($config['apiKey'])) {
            throw new \InvalidArgumentException('AllStak SDK: apiKey is required and must be non-empty');
        }

        // Host is hardcoded to INGEST_HOST. The optional 'host' config key is
        // accepted for tests/integration injection only and never advertised in
        // public docs.
        $host = rtrim($config['host'] ?? self::INGEST_HOST, '/');
        $env = $config['environment'] ?? '';

        $this->apiKey = $config['apiKey'];
        $this->host = $host;
        $this->environment = $env;
        $this->release = $config['release'] ?? '';
        $this->flushIntervalMs = $config['flushIntervalMs'] ?? 5000;
        $this->bufferSize = $config['bufferSize'] ?? 500;
        $this->debug = $config['debug'] ?? false;

        $this->autoBreadcrumbs = $config['autoBreadcrumbs'] ?? true;
        $this->maxBreadcrumbs = $config['maxBreadcrumbs'] ?? 50;

        $this->bearerToken = $config['bearerToken'] ?? '';
        $this->projectId = $config['projectId'] ?? '';
        $this->flagCacheTtlSeconds = $config['flagCacheTtlSeconds'] ?? 60;

        $this->connectTimeoutMs = $config['connectTimeoutMs'] ?? 3000;
        $this->readTimeoutMs = $config['readTimeoutMs'] ?? 3000;
        $this->totalTimeoutMs = $config['totalTimeoutMs'] ?? 5000;
        $this->maxRetries = $config['maxRetries'] ?? 5;
    }
}
