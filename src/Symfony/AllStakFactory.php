<?php

declare(strict_types=1);

namespace AllStak\Symfony;

use AllStak\AllStak;

/**
 * Builds (or reuses) the AllStak singleton from bundle configuration.
 *
 * The SDK is a process-wide singleton initialised via {@see AllStak::init()}.
 * This factory bridges that into Symfony's container so AllStak can be
 * autowired as a normal service while preserving the single-instance
 * guarantee: if init() has already run (e.g. via app bootstrap) the existing
 * instance is reused.
 */
final class AllStakFactory
{
    /**
     * @param array<string,mixed> $config Bundle config (snake_case keys).
     */
    public static function create(array $config): ?AllStak
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            // No key configured — the SDK stays uninitialised and every
            // integration becomes a no-op. Never break the host app.
            return AllStak::getInstance();
        }

        $existing = AllStak::getInstance();
        if ($existing !== null) {
            $service = (string) ($config['service'] ?? '');
            if ($service !== '') {
                $existing->setServiceName($service);
            }
            return $existing;
        }

        $initConfig = [
            'apiKey' => $apiKey,
            'environment' => (string) ($config['environment'] ?? ''),
            'release' => (string) ($config['release'] ?? ''),
            'debug' => (bool) ($config['debug'] ?? false),
            'autoBreadcrumbs' => (bool) ($config['auto_breadcrumbs'] ?? true),
        ];

        // Optional ingest host override — self-hosted deployments and the
        // SDK's own integration tests only. Production leaves this unset.
        $host = (string) ($config['host'] ?? '');
        if ($host !== '') {
            $initConfig['host'] = $host;
        }

        $sdk = AllStak::init($initConfig);

        $service = (string) ($config['service'] ?? '');
        if ($service !== '') {
            $sdk->setServiceName($service);
        }

        return $sdk;
    }
}
