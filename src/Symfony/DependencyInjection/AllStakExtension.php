<?php

declare(strict_types=1);

namespace AllStak\Symfony\DependencyInjection;

use AllStak\AllStak;
use AllStak\Monolog\AllStakHandler;
use AllStak\Symfony\AllStakFactory;
use AllStak\Symfony\EventListener\ExceptionSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * DI extension for the AllStak Symfony bundle.
 *
 * Wires:
 *   - allstak (AllStak): the SDK instance, built from config via
 *     {@see AllStakFactory::create()}. Aliased to the AllStak FQCN for
 *     autowiring.
 *   - allstak.exception_subscriber: the kernel.exception / kernel.request
 *     event subscriber.
 *   - allstak.monolog_handler (optional): the Monolog handler, registered
 *     only when `allstak.monolog.enabled` is true.
 */
final class AllStakExtension extends Extension
{
    public function getAlias(): string
    {
        return 'allstak';
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // The SDK instance, produced by the factory from resolved config.
        $sdkDefinition = new Definition(AllStak::class);
        $sdkDefinition->setFactory([AllStakFactory::class, 'create']);
        $sdkDefinition->setArguments([$config]);
        $sdkDefinition->setPublic(true);
        $container->setDefinition('allstak', $sdkDefinition);
        $container->setAlias(AllStak::class, 'allstak')->setPublic(true);

        // Exception / request subscriber.
        $subscriber = new Definition(ExceptionSubscriber::class);
        $subscriber->setArguments([
            new Reference('allstak'),
            (bool) $config['capture_exceptions'],
            (bool) $config['capture_requests'],
            (float) $config['sample_rate'],
            null, // before_send is wired by the app via a compiler pass / decoration
        ]);
        $subscriber->addTag('kernel.event_subscriber');
        $subscriber->setPublic(true);
        $container->setDefinition('allstak.exception_subscriber', $subscriber);
        $container->setAlias(ExceptionSubscriber::class, 'allstak.exception_subscriber');

        // Optional Monolog handler service.
        if (!empty($config['monolog']['enabled'])) {
            $handler = new Definition(AllStakHandler::class);
            $handler->setArguments([
                new Reference('allstak'),
                (string) $config['monolog']['level'],
                (string) $config['monolog']['event_level'],
            ]);
            $handler->setPublic(true);
            $container->setDefinition('allstak.monolog_handler', $handler);
            $container->setAlias(AllStakHandler::class, 'allstak.monolog_handler');
        }

        $container->setParameter('allstak.config', $config);
    }
}
