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
    public const INGEST_HOST = 'https://api.allstak.sa';

    /** SDK version. Surfaced in the User-Agent header sent to the ingest backend, and as `sdk.version` in event metadata. */
    public const VERSION = '1.2.2';
    /** SDK package name — sent on the wire as `sdk.name`. */
    public const SDK_NAME = 'allstak-php';

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

    // Release-tracking metadata (auto-detected from $_ENV / getenv where possible).
    public readonly string $dist;
    public readonly string $commitSha;
    public readonly string $branch;
    public readonly string $platform;
    public readonly string $sdkName;
    public readonly string $sdkVersion;

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

        // Release-tracking metadata. Explicit config wins, then env vars.
        $envFirst = static function (array $keys): string {
            foreach ($keys as $k) { $v = getenv($k); if ($v !== false && $v !== '') return $v; }
            return '';
        };
        $this->dist       = (string)($config['dist'] ?? '');
        $this->commitSha  = (string)($config['commitSha'] ?? $envFirst(['ALLSTAK_COMMIT_SHA', 'GIT_COMMIT', 'VERCEL_GIT_COMMIT_SHA', 'RAILWAY_GIT_COMMIT_SHA', 'RENDER_GIT_COMMIT']));
        $this->branch     = (string)($config['branch'] ?? $envFirst(['ALLSTAK_BRANCH', 'GIT_BRANCH', 'VERCEL_GIT_COMMIT_REF', 'RAILWAY_GIT_BRANCH']));
        $this->platform   = (string)($config['platform'] ?? 'php');
        $this->sdkName    = (string)($config['sdkName'] ?? self::SDK_NAME);
        $this->sdkVersion = (string)($config['sdkVersion'] ?? self::VERSION);
    }

    /**
     * Release-tracking tags merged into every event payload's metadata so the
     * dashboard can group / filter by SDK / platform / commit / branch.
     * @return array<string, string>
     */
    public function releaseTags(): array
    {
        $out = [];
        if ($this->sdkName !== '') $out['sdk.name'] = $this->sdkName;
        if ($this->sdkVersion !== '') $out['sdk.version'] = $this->sdkVersion;
        if ($this->platform !== '') $out['platform'] = $this->platform;
        if ($this->dist !== '') $out['dist'] = $this->dist;
        if ($this->commitSha !== '') $out['commit.sha'] = $this->commitSha;
        if ($this->branch !== '') $out['commit.branch'] = $this->branch;
        return $out;
    }
}
