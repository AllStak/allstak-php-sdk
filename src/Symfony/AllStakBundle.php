<?php

declare(strict_types=1);

namespace AllStak\Symfony;

use AllStak\Symfony\DependencyInjection\AllStakExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * AllStak Symfony bundle.
 *
 * Register it in config/bundles.php:
 *
 *     return [
 *         // ...
 *         AllStak\Symfony\AllStakBundle::class => ['all' => true],
 *     ];
 *
 * Then configure it in config/packages/allstak.yaml (see the bundle's
 * {@see DependencyInjection\Configuration}). At minimum set `api_key`.
 *
 * The bundle:
 *   - initialises the AllStak SDK from config (api key, host, environment,
 *     release, service, debug, auto_breadcrumbs),
 *   - registers an event subscriber that captures unhandled kernel
 *     exceptions (KernelEvents::EXCEPTION) and attaches request context
 *     (KernelEvents::REQUEST), with optional sample_rate / before_send,
 *   - optionally exposes the AllStak Monolog handler as a service so it can
 *     be plugged into Symfony's Monolog stack.
 */
final class AllStakBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new AllStakExtension();
        }

        return $this->extension ?: null;
    }
}
