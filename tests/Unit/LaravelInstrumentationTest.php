<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Laravel\AllStakServiceProvider;
use AllStak\Tests\Support\FakeQueueJob;
use AllStak\Tests\Support\MockServerTestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require_once __DIR__ . '/../Support/laravel-event-stubs.php';

/**
 * Drives every auto-instrumentation listener registered by the Laravel
 * {@see AllStakServiceProvider} and asserts the resulting telemetry on the wire
 * via the mock ingest server.
 *
 * The provider depends on Laravel's container/foundation helpers in boot(), so
 * rather than booting a full app we bind the {@see Event} facade to a real
 * {@see Dispatcher}, init the SDK against the mock server, invoke the private
 * register*Instrumentation() methods directly, and then dispatch synthetic
 * framework events. This exercises the exact listener bodies that ship.
 */
final class LaravelInstrumentationTest extends MockServerTestCase
{
    private Container $container;
    private Dispatcher $dispatcher;
    private AllStakServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->dispatcher = new Dispatcher($this->container);
        $this->container->instance('events', $this->dispatcher);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);

        $this->provider = new AllStakServiceProvider($this->container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /** Invoke a private provider method by name. */
    private function invoke(string $method, mixed ...$args): void
    {
        $m = new ReflectionMethod(AllStakServiceProvider::class, $method);
        $m->setAccessible(true);
        $m->invoke($this->provider, ...$args);
    }

    /** Decode all span payloads the mock server received, flattened. */
    private function recordedSpans(): array
    {
        $spans = [];
        foreach ($this->requestsForPath('/ingest/v1/spans') as $req) {
            foreach (($req['payload']['spans'] ?? []) as $span) {
                $spans[] = $span;
            }
        }
        return $spans;
    }

    // ─── Queue ────────────────────────────────────────────────────────

    public function testQueueJobProcessingOpensAndClosesSpan(): void
    {
        $sdk = $this->initSdk();
        $this->invoke('registerQueueInstrumentation', $sdk);

        $job = new FakeQueueJob('App\\Jobs\\SendEmail');
        $this->dispatcher->dispatch(new JobProcessing('redis', $job));
        $this->dispatcher->dispatch(new JobProcessed('redis', $job));

        $sdk->flush();

        $spans = $this->recordedSpans();
        $queueSpans = array_values(array_filter(
            $spans,
            static fn(array $s): bool => ($s['operation'] ?? '') === 'queue.process'
        ));
        $this->assertNotEmpty($queueSpans, 'queue.process span should be emitted');
        $this->assertSame('App\\Jobs\\SendEmail', $queueSpans[0]['description']);
        $this->assertSame('ok', $queueSpans[0]['status']);
    }

    public function testQueueJobFailedCapturesErrorAndClosesSpanWithError(): void
    {
        $sdk = $this->initSdk();
        $this->invoke('registerQueueInstrumentation', $sdk);

        $job = new FakeQueueJob('App\\Jobs\\Boom');
        $this->dispatcher->dispatch(new JobProcessing('database', $job));
        $this->dispatcher->dispatch(new JobFailed('database', $job, new RuntimeException('kaboom')));

        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertNotEmpty($errors, 'a failed job should capture an error');
        $this->assertSame('RuntimeException', $errors[0]['payload']['exceptionClass']);

        $queueSpans = array_values(array_filter(
            $this->recordedSpans(),
            static fn(array $s): bool => ($s['operation'] ?? '') === 'queue.process'
        ));
        $this->assertNotEmpty($queueSpans);
        $this->assertSame('error', $queueSpans[0]['status']);
    }

    // ─── Console ────────────────────────────────────────────────────────

    public function testConsoleCommandLifecycleEmitsSpan(): void
    {
        $sdk = $this->initSdk();
        $this->invoke('registerConsoleInstrumentation', $sdk);

        $input = new ArrayInput([]);
        $output = new NullOutput();
        $this->dispatcher->dispatch(new CommandStarting('migrate', $input, $output));
        $this->dispatcher->dispatch(new CommandFinished('migrate', $input, $output, 0));

        $sdk->flush();

        $spans = array_values(array_filter(
            $this->recordedSpans(),
            static fn(array $s): bool => ($s['operation'] ?? '') === 'console.command'
        ));
        $this->assertNotEmpty($spans, 'console.command span should be emitted');
        $this->assertSame('migrate', $spans[0]['description']);
        $this->assertSame('ok', $spans[0]['status']);
        // tags is a JSON object on the wire; it decodes back to an assoc array.
        $this->assertSame('0', $spans[0]['tags']['console.exit_code'] ?? null);
    }

    public function testConsoleCommandNonZeroExitMarksSpanError(): void
    {
        $sdk = $this->initSdk();
        $this->invoke('registerConsoleInstrumentation', $sdk);

        $input = new ArrayInput([]);
        $output = new NullOutput();
        $this->dispatcher->dispatch(new CommandStarting('deploy', $input, $output));
        $this->dispatcher->dispatch(new CommandFinished('deploy', $input, $output, 1));

        $sdk->flush();

        $spans = array_values(array_filter(
            $this->recordedSpans(),
            static fn(array $s): bool => ($s['operation'] ?? '') === 'console.command'
        ));
        $this->assertNotEmpty($spans);
        $this->assertSame('error', $spans[0]['status']);
    }

    // ─── Scheduled tasks ─────────────────────────────────────────────────

    /**
     * Build a real ScheduledTask* event instance (so its get_class matches the
     * registered listener) carrying our fake task, without invoking the
     * framework constructor whose typed args we don't want to satisfy.
     */
    private function scheduledEvent(string $eventClass, object $task): object
    {
        $ref = new \ReflectionClass($eventClass);
        $event = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('task');
        $prop->setAccessible(true);
        $prop->setValue($event, $task);
        return $event;
    }

    public function testScheduledTaskHeartbeatCanBeDisabled(): void
    {
        $sdk = $this->initSdk();
        // heartbeat = false: lifecycle still observed, but no /heartbeat POST.
        $this->invoke('registerScheduledTaskInstrumentation', $sdk, false);

        // The listener only reads ->task->description / ->command, so a plain
        // holder is sufficient as the task.
        $task = (object) ['description' => 'app:digest', 'command' => 'app:digest'];
        $this->dispatcher->dispatch($this->scheduledEvent(ScheduledTaskStarting::class, $task));
        $this->dispatcher->dispatch($this->scheduledEvent(ScheduledTaskFinished::class, $task));

        $sdk->flush();

        $this->assertEmpty(
            $this->requestsForPath('/ingest/v1/heartbeat'),
            'no heartbeat should be sent when scheduled_task_heartbeat is off'
        );
    }

    public function testScheduledTaskHeartbeatEnabledSendsHeartbeat(): void
    {
        $sdk = $this->initSdk();
        $this->invoke('registerScheduledTaskInstrumentation', $sdk, true);

        $task = (object) ['description' => 'app:digest', 'command' => 'app:digest'];
        $this->dispatcher->dispatch($this->scheduledEvent(ScheduledTaskStarting::class, $task));
        $this->dispatcher->dispatch($this->scheduledEvent(ScheduledTaskFinished::class, $task));

        $sdk->flush();

        $beats = $this->requestsForPath('/ingest/v1/heartbeat');
        $this->assertNotEmpty($beats, 'a heartbeat should be sent for a finished task');
        $this->assertSame('app-digest', $beats[0]['payload']['slug']);
        $this->assertSame('success', $beats[0]['payload']['status']);
    }

    // ─── Logs ─────────────────────────────────────────────────────────

    public function testLoggedThrowableAtErrorIsPromotedToCapturedError(): void
    {
        $sdk = $this->initSdk();
        $this->registerLogListener($sdk);

        $this->dispatcher->dispatch(new MessageLogged(
            'error',
            'Something failed',
            ['exception' => new RuntimeException('promoted')]
        ));

        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertNotEmpty($errors, 'logged throwable at error level should be promoted');
        $this->assertSame('RuntimeException', $errors[0]['payload']['exceptionClass']);

        // It should ALSO have shipped as a log line (preserve existing behavior).
        $this->assertNotEmpty($this->requestsForPath('/ingest/v1/logs'));
    }

    public function testInfoLogIsNotPromotedToError(): void
    {
        $sdk = $this->initSdk();
        $this->registerLogListener($sdk);

        $this->dispatcher->dispatch(new MessageLogged('info', 'just info', []));
        $sdk->flush();

        $this->assertEmpty(
            $this->requestsForPath('/ingest/v1/errors'),
            'info-level logs must never be promoted to errors'
        );
        $this->assertNotEmpty($this->requestsForPath('/ingest/v1/logs'));
    }

    /**
     * Mirror the MessageLogged listener the provider wires in boot(). boot()
     * needs config()/foundation helpers we don't bootstrap here, so we register
     * the identical closure shape against the real dispatcher.
     */
    private function registerLogListener(AllStak $sdk): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($sdk): void {
            $level = match ($event->level) {
                'emergency', 'alert', 'critical' => 'fatal',
                'error' => 'error',
                'warning', 'notice' => 'warn',
                'info' => 'info',
                'debug' => 'debug',
                default => 'info',
            };
            $context = is_array($event->context) ? $event->context : [];
            $sdk->captureLog($level, (string) $event->message, $context);
            if (in_array($level, ['error', 'fatal'], true)
                && isset($context['exception'])
                && $context['exception'] instanceof \Throwable) {
                $sdk->captureError($context['exception'], ['level' => $level]);
            }
        });
    }

    // ─── Guarded optional integrations (degrade gracefully) ───────────────

    /**
     * Cache / Redis / Livewire / Octane components are not installed in this
     * test environment. Their registration must be a safe no-op that never
     * throws and never registers a live listener.
     */
    public function testGuardedIntegrationsAreSafeNoOpsWhenAbsent(): void
    {
        $sdk = $this->initSdk();

        $this->invoke('registerCacheInstrumentation', $sdk);
        $this->invoke('registerRedisInstrumentation', $sdk);
        $this->invoke('registerLivewireInstrumentation', $sdk);
        $this->invoke('registerOctaneReset', $sdk);

        // None of the optional event names should have a live listener.
        $this->assertFalse($this->dispatcher->hasListeners('Illuminate\\Cache\\Events\\CacheHit'));
        $this->assertFalse($this->dispatcher->hasListeners('Illuminate\\Redis\\Events\\CommandExecuted'));
        $this->assertFalse($this->dispatcher->hasListeners('Laravel\\Octane\\Events\\RequestReceived'));

        // And nothing was sent to the wire as a side effect of registration.
        $this->assertEmpty($this->recordedRequests());
    }

    public function testViewComposingEmitsBreadcrumbWiredOntoNextError(): void
    {
        // The View Factory contract IS present in the dev deps, so the view
        // listener registers. A composed view should leave a breadcrumb that
        // rides on the next captured error.
        $sdk = $this->initSdk();
        $this->invoke('registerViewInstrumentation', $sdk);

        $view = new class {
            public function name(): string
            {
                return 'emails.welcome';
            }
        };
        $this->dispatcher->dispatch('composing: emails.welcome', [$view]);

        $sdk->captureError(new RuntimeException('after view'));
        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertNotEmpty($errors);
        $crumbs = $errors[0]['payload']['breadcrumbs'] ?? [];
        $messages = array_map(static fn(array $c): string => $c['message'] ?? '', $crumbs);
        $this->assertContains('View composing: emails.welcome', $messages);
    }
}
