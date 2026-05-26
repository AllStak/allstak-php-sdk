<?php

declare(strict_types=1);

namespace AllStak\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration schema for the AllStak Symfony bundle.
 *
 * Example (config/packages/allstak.yaml):
 *
 *     allstak:
 *         api_key: '%env(ALLSTAK_API_KEY)%'
 *         environment: '%kernel.environment%'
 *         release: '%env(default::ALLSTAK_RELEASE)%'
 *         service: 'my-symfony-app'
 *         debug: false
 *         auto_breadcrumbs: true
 *         capture_exceptions: true
 *         capture_requests: true
 *         # Optional self-hosted / test ingest host override.
 *         host: ''
 *         monolog:
 *             enabled: false
 *             level: 'debug'
 *             event_level: 'error'
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('allstak');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('api_key')
                    ->defaultValue('')
                    ->info('AllStak project API key. The bundle is a no-op when empty.')
                ->end()
                ->scalarNode('host')
                    ->defaultValue('')
                    ->info('Optional ingest host override for self-hosted AllStak or integration tests. Leave empty for production.')
                ->end()
                ->scalarNode('environment')
                    ->defaultValue('')
                    ->info('Deployment environment (production / staging / dev).')
                ->end()
                ->scalarNode('release')
                    ->defaultValue('')
                    ->info('Release / build identifier (git SHA, semver, ...).')
                ->end()
                ->scalarNode('service')
                    ->defaultValue('')
                    ->info('Logical service name attached to events.')
                ->end()
                ->booleanNode('debug')
                    ->defaultFalse()
                    ->info('Verbose SDK debug logging to STDERR.')
                ->end()
                ->booleanNode('auto_breadcrumbs')
                    ->defaultTrue()
                ->end()
                ->floatNode('sample_rate')
                    ->defaultValue(1.0)
                    ->min(0.0)->max(1.0)
                    ->info('Fraction of captured exceptions to forward (0.0-1.0). 1.0 = capture everything.')
                ->end()
                ->booleanNode('capture_exceptions')
                    ->defaultTrue()
                    ->info('Capture unhandled kernel exceptions (KernelEvents::EXCEPTION).')
                ->end()
                ->booleanNode('capture_requests')
                    ->defaultTrue()
                    ->info('Attach request context (method/path/host) from KernelEvents::REQUEST.')
                ->end()
                ->arrayNode('monolog')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Register the AllStak Monolog handler as a service (allstak.monolog_handler).')
                        ->end()
                        ->scalarNode('level')
                            ->defaultValue('debug')
                            ->info('Minimum Monolog level the handler reacts to.')
                        ->end()
                        ->scalarNode('event_level')
                            ->defaultValue('error')
                            ->info('Monolog level at/above which records become events (lower become breadcrumbs).')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
