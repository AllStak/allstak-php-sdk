<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Monolog\AllStakHandler;
use AllStak\Symfony\AllStakBundle;
use AllStak\Symfony\DependencyInjection\AllStakExtension;
use AllStak\Symfony\DependencyInjection\Configuration;
use AllStak\Symfony\EventListener\ExceptionSubscriber;
use AllStak\Tests\Support\MockServerTestCase;
use RuntimeException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SymfonyBundleTest extends MockServerTestCase
{
    // ─── DI extension / configuration ────────────────────────────────

    public function testExtensionBuildsSdkServiceFromConfig(): void
    {
        $container = new ContainerBuilder();
        $extension = new AllStakExtension();
        $extension->load([[
            'api_key' => 'allstak_live_test',
            'host' => $this->host,
            'environment' => 'staging',
            'service' => 'checkout-svc',
        ]], $container);

        $this->assertTrue($container->hasDefinition('allstak'));
        $this->assertTrue($container->hasDefinition('allstak.exception_subscriber'));

        // Resolve through the factory and assert the SDK was wired from config.
        $container->compile();
        $sdk = $container->get('allstak');

        $this->assertInstanceOf(AllStak::class, $sdk);
        $this->assertSame('allstak_live_test', $sdk->getOptions()->apiKey);
        $this->assertSame('staging', $sdk->getOptions()->environment);
        $this->assertSame($this->host, $sdk->getOptions()->host);

        // Subscriber is tagged as a kernel event subscriber.
        $subscriberDef = $container->getDefinition('allstak.exception_subscriber');
        $this->assertNotEmpty($subscriberDef->getTag('kernel.event_subscriber'));
    }

    public function testMonologHandlerServiceWiredWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new AllStakExtension();
        $extension->load([[
            'api_key' => 'allstak_live_test',
            'host' => $this->host,
            'monolog' => ['enabled' => true, 'level' => 'debug', 'event_level' => 'error'],
        ]], $container);

        $this->assertTrue($container->hasDefinition('allstak.monolog_handler'));
        $container->compile();
        $this->assertInstanceOf(AllStakHandler::class, $container->get('allstak.monolog_handler'));
    }

    public function testMonologHandlerNotWiredByDefault(): void
    {
        $container = new ContainerBuilder();
        (new AllStakExtension())->load([[
            'api_key' => 'allstak_live_test',
            'host' => $this->host,
        ]], $container);

        $this->assertFalse($container->hasDefinition('allstak.monolog_handler'));
    }

    public function testConfigurationDefaults(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'api_key' => 'k',
        ]]);

        $this->assertSame('k', $processed['api_key']);
        $this->assertTrue($processed['capture_exceptions']);
        $this->assertTrue($processed['capture_requests']);
        $this->assertSame(1.0, $processed['sample_rate']);
        $this->assertFalse($processed['monolog']['enabled']);
        $this->assertSame('error', $processed['monolog']['event_level']);
    }

    public function testBundleExposesExtension(): void
    {
        $bundle = new AllStakBundle();
        $this->assertInstanceOf(AllStakExtension::class, $bundle->getContainerExtension());
    }

    // ─── Exception subscriber ────────────────────────────────────────

    public function testSubscriberCapturesKernelException(): void
    {
        $sdk = $this->initSdk();
        $subscriber = new ExceptionSubscriber($sdk);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://shop.test/checkout', 'POST');

        // Set request context first (as kernel.request would).
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $exception = new RuntimeException('kernel boom');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $subscriber->onKernelException($event);

        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors);
        $payload = $errors[0]['payload'];
        $this->assertSame('RuntimeException', $payload['exceptionClass']);
        $this->assertSame('kernel boom', $payload['message']);
        $this->assertNotEmpty($payload['stackTrace']);

        // Request context attached from kernel.request.
        $this->assertArrayHasKey('requestContext', $payload);
        $this->assertSame('POST', $payload['requestContext']['method']);
        $this->assertSame('/checkout', $payload['requestContext']['path']);
        $this->assertSame('shop.test', $payload['requestContext']['host']);
    }

    public function testSubscriberRespectsCaptureExceptionsFalse(): void
    {
        $sdk = $this->initSdk();
        $subscriber = new ExceptionSubscriber($sdk, captureExceptions: false);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://shop.test/');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('ignored'));
        $subscriber->onKernelException($event);
        $sdk->flush();

        $this->assertCount(0, $this->requestsForPath('/ingest/v1/errors'));
    }

    public function testBeforeSendCanDropEvent(): void
    {
        $sdk = $this->initSdk();
        $subscriber = new ExceptionSubscriber(
            $sdk,
            beforeSend: fn(\Throwable $e, array $hint): ?\Throwable => null // drop all
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://shop.test/');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('dropped'));
        $subscriber->onKernelException($event);
        $sdk->flush();

        $this->assertCount(0, $this->requestsForPath('/ingest/v1/errors'));
    }

    public function testBeforeSendReceivesSanitizedThrowableAndCannotReintroduceSecrets(): void
    {
        $sdk = $this->initSdk();
        $seen = null;
        $subscriber = new ExceptionSubscriber(
            $sdk,
            beforeSend: function (\Throwable $e, array $hint) use (&$seen): ?\Throwable {
                $seen = $e;
                return new RuntimeException('card 4111111111111111');
            }
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://shop.test/');
        $event = new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new RuntimeException('card 4111111111111111')
        );
        $subscriber->onKernelException($event);
        $sdk->flush();

        $this->assertInstanceOf(\Throwable::class, $seen);
        $this->assertSame('card [REDACTED]', $seen->getMessage());
        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors);
        $this->assertSame('card [REDACTED]', $errors[0]['payload']['message']);
    }

    public function testSampleRateZeroDropsEvents(): void
    {
        $sdk = $this->initSdk();
        $subscriber = new ExceptionSubscriber($sdk, sampleRate: 0.0);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://shop.test/');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('sampled out'));
        $subscriber->onKernelException($event);
        $sdk->flush();

        $this->assertCount(0, $this->requestsForPath('/ingest/v1/errors'));
    }

    public function testSubscribedEventsList(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }
}
