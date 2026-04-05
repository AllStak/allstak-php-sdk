<?php

declare(strict_types=1);

namespace AllStak\Config;

final class Options
{
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
        if (empty($config['host'])) {
            throw new \InvalidArgumentException('AllStak SDK: host is required and must be non-empty');
        }

        $host = rtrim($config['host'], '/');
        $env = $config['environment'] ?? '';

        // Refuse HTTP in production
        if ($env === 'production' && !str_starts_with($host, 'https://')) {
            throw new \InvalidArgumentException('AllStak SDK: HTTPS is required in production environment');
        }

        $this->apiKey = $config['apiKey'];
        $this->host = $host;
        $this->environment = $env;
        $this->release = $config['release'] ?? '';
        $this->flushIntervalMs = $config['flushIntervalMs'] ?? 5000;
        $this->bufferSize = $config['bufferSize'] ?? 500;
        $this->debug = $config['debug'] ?? false;

        $this->bearerToken = $config['bearerToken'] ?? '';
        $this->projectId = $config['projectId'] ?? '';
        $this->flagCacheTtlSeconds = $config['flagCacheTtlSeconds'] ?? 60;

        $this->connectTimeoutMs = $config['connectTimeoutMs'] ?? 3000;
        $this->readTimeoutMs = $config['readTimeoutMs'] ?? 3000;
        $this->totalTimeoutMs = $config['totalTimeoutMs'] ?? 5000;
        $this->maxRetries = $config['maxRetries'] ?? 5;
    }
}
