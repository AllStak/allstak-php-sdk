<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\Config\Options;
use AllStak\Models\FlagResult;
use AllStak\SdkLogger;
use AllStak\Transport\HttpClient;

final class FeatureFlags
{
    private HttpClient $client;
    private SdkLogger $logger;
    private Options $options;

    /** @var array<string, array{result: FlagResult, expiresAt: float}> */
    private array $cache = [];

    /** @var array<string, array{flags: array<string, FlagResult>, expiresAt: float}> */
    private array $allFlagsCache = [];

    public function __construct(HttpClient $client, SdkLogger $logger, Options $options)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->options = $options;
    }

    public function evaluateFlag(string $key, ?string $userId = null, array $attributes = []): ?FlagResult
    {
        $cacheKey = $this->buildCacheKey($key, $userId, $attributes);

        // Check cache
        if (isset($this->cache[$cacheKey])) {
            $entry = $this->cache[$cacheKey];
            if ($entry['expiresAt'] > microtime(true)) {
                $this->logger->debug("Flag cache hit: {$key}");
                return $entry['result'];
            }
        }

        // Fetch from backend
        $params = ['projectId' => $this->options->projectId];
        if ($userId !== null) {
            $params['userId'] = $userId;
        }
        if (!empty($attributes)) {
            $params['attributes'] = json_encode($attributes);
        }

        $result = $this->client->getManagement("/api/v1/flags/{$key}/evaluate", $params);

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300 && isset($result['body']['data'])) {
            $data = $result['body']['data'];
            $flagResult = new FlagResult(
                key: $data['key'] ?? $key,
                enabled: $data['enabled'] ?? false,
                value: $data['value'] ?? '',
                ruleApplied: $data['ruleApplied'] ?? '',
            );

            $this->cache[$cacheKey] = [
                'result' => $flagResult,
                'expiresAt' => microtime(true) + $this->options->flagCacheTtlSeconds,
            ];

            return $flagResult;
        }

        // On network error, return stale cache if available
        if (isset($this->cache[$cacheKey])) {
            $this->logger->debug("Flag fetch failed, returning stale cache for: {$key}");
            return $this->cache[$cacheKey]['result'];
        }

        $this->logger->debug("Flag fetch failed, no cache for: {$key}");
        return null;
    }

    /**
     * @return array<string, FlagResult>
     */
    public function evaluateAll(?string $userId = null, array $attributes = []): array
    {
        $cacheKey = $this->buildCacheKey('__all__', $userId, $attributes);

        // Check cache
        if (isset($this->allFlagsCache[$cacheKey])) {
            $entry = $this->allFlagsCache[$cacheKey];
            if ($entry['expiresAt'] > microtime(true)) {
                $this->logger->debug('All flags cache hit');
                return $entry['flags'];
            }
        }

        $params = ['projectId' => $this->options->projectId];
        if ($userId !== null) {
            $params['userId'] = $userId;
        }
        if (!empty($attributes)) {
            $params['attributes'] = json_encode($attributes);
        }

        $result = $this->client->getManagement('/api/v1/flags/evaluate', $params);

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300 && isset($result['body']['data']['flags'])) {
            $flags = [];
            foreach ($result['body']['data']['flags'] as $key => $data) {
                $flags[$key] = new FlagResult(
                    key: $key,
                    enabled: $data['enabled'] ?? false,
                    value: $data['value'] ?? '',
                );
            }

            $this->allFlagsCache[$cacheKey] = [
                'flags' => $flags,
                'expiresAt' => microtime(true) + $this->options->flagCacheTtlSeconds,
            ];

            return $flags;
        }

        // Stale cache fallback
        if (isset($this->allFlagsCache[$cacheKey])) {
            $this->logger->debug('All flags fetch failed, returning stale cache');
            return $this->allFlagsCache[$cacheKey]['flags'];
        }

        return [];
    }

    private function buildCacheKey(string $key, ?string $userId, array $attributes): string
    {
        return md5($key . '|' . ($userId ?? '') . '|' . json_encode($attributes));
    }
}
