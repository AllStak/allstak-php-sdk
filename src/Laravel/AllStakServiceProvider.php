<?php

declare(strict_types=1);

namespace AllStak\Laravel;

use AllStak\AllStak;
use AllStak\Models\JobHandle;
use AllStak\Models\UserContext;
use GuzzleHttp\Promise\Create as GuzzleCreate;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Drop-in Laravel integration for the AllStak PHP SDK.
 *
 * Installs:
 *   - SDK initialization from config('allstak.api_key' / 'environment' / 'release' / 'service')
 *   - Inbound HTTP request capture (via global middleware AllStakRequestMiddleware)
 *   - Unhandled exception capture (hook into Illuminate Foundation exception handler)
 *   - Log capture via Laravel's MessageLogged event
 *   - DB query capture via DB::listen
 *   - Outbound HTTP capture via the Http client ResponseReceived event
 *   - 'allstak' log channel that ships logs through the SDK
 *
 * Config: customers only need to set ALLSTAK_API_KEY in their .env. Everything
 * else has sensible defaults pulled from app.env / app.name / app.version.
 */
class AllStakServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/allstak.php', 'allstak');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/allstak.php' => $this->app->configPath('allstak.php'),
        ], 'allstak-config');

        $apiKey = (string) config('allstak.api_key', '');
        if ($apiKey === '') {
            // Silently no-op when no API key is configured. Customers can still call
            // AllStak::init() manually if they prefer.
            return;
        }

        if (AllStak::getInstance() === null) {
            $config = [
                'apiKey' => $apiKey,
                'environment' => (string) config('allstak.environment', $this->app->environment()),
                'release' => (string) config('allstak.release', ''),
                'debug' => (bool) config('allstak.debug', false),
                'autoBreadcrumbs' => (bool) config('allstak.auto_breadcrumbs', true),
            ];
            // Optional host override — only for self-hosted AllStak deployments
            // and for the SDK's own integration tests. Production customers leave
            // this unset and the SDK uses the static Options::INGEST_HOST.
            $hostOverride = (string) config('allstak.host', '');
            if ($hostOverride !== '') {
                $config['host'] = $hostOverride;
            }
            AllStak::init($config);
        }

        $sdk = AllStak::getInstance();
        if ($sdk === null) {
            return;
        }

        $service = (string) config('allstak.service', config('app.name', 'laravel'));
        $sdk->setServiceName($service);

        if ((bool) config('allstak.capture_requests', true)) {
            /** @var HttpKernel $kernel */
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(AllStakRequestMiddleware::class);
            }
        }

        if ((bool) config('allstak.capture_exceptions', true)) {
            $this->hookExceptionHandler($sdk);
        }

        if ((bool) config('allstak.capture_logs', true)) {
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
                try {
                    $sdk->captureLog($level, (string) $event->message, $context);

                    // Promote logged throwables at error+ to a grouped error.
                    // Laravel's exception logging passes the Throwable under the
                    // 'exception' context key; report() goes through the
                    // reportable() hook above, but a direct Log::error($msg,
                    // ['exception' => $e]) would otherwise only be a log line.
                    if (in_array($level, ['error', 'fatal'], true)
                        && isset($context['exception'])
                        && $context['exception'] instanceof Throwable) {
                        $sdk->captureError($context['exception'], ['level' => $level]);
                    }
                } catch (Throwable $e) {
                    // never break the host app
                }
            });
        }

        if ((bool) config('allstak.capture_db', true)) {
            DB::listen(function ($query) use ($sdk): void {
                try {
                    $sdk->getDatabaseMonitor()?->recordQuery(
                        $query->sql,
                        (float) $query->time,
                        'success',
                        null,
                        (string) ($query->connection?->getDatabaseName() ?? ''),
                        (string) ($query->connection?->getDriverName() ?? ''),
                        -1,
                        $sdk->getTraceId(),
                        $sdk->getCurrentSpanId() ?? ''
                    );
                } catch (Throwable $e) {
                    // best effort
                }
            });
        }

        if ((bool) config('allstak.capture_http_client', true)) {
            $this->registerOutboundHttpInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_scheduled_tasks', true)) {
            $this->registerScheduledTaskInstrumentation(
                $sdk,
                (bool) config('allstak.scheduled_task_heartbeat', true)
            );
        }

        if ((bool) config('allstak.capture_queue', true)) {
            $this->registerQueueInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_console', true)) {
            $this->registerConsoleInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_cache', true)) {
            $this->registerCacheInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_redis', true)) {
            $this->registerRedisInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_views', true)) {
            $this->registerViewInstrumentation($sdk);
        }

        if ((bool) config('allstak.capture_livewire', true)) {
            $this->registerLivewireInstrumentation($sdk);
        }

        if ((bool) config('allstak.octane_reset', true)) {
            $this->registerOctaneReset($sdk);
        }

        // Drain SDK buffers when Laravel terminates a request
        $this->app->terminating(function () use ($sdk): void {
            try {
                $sdk->flush();
            } catch (Throwable $e) {
                // never break termination
            }
        });
    }

    /**
     * Hook into the Foundation exception handler so unhandled exceptions are captured.
     * Works with both Laravel 10/11 (`reportable` callback) and earlier versions
     * (override of report()).
     */
    private function hookExceptionHandler(AllStak $sdk): void
    {
        try {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            if ($handler instanceof FoundationHandler && method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) use ($sdk): void {
                    try {
                        // Auth state is fully loaded at error-report time —
                        // attach the authenticated user automatically.
                        if (Auth::check()) {
                            $u = Auth::user();
                            // Auto-collected client IP is PII: only attach when
                            // the host app opted in via sendDefaultPii. Default
                            // drops it. Explicit setUser(...) is unaffected.
                            $autoIp = $sdk->getOptions()->sendDefaultPii
                                ? (string) (request()?->ip() ?? '')
                                : '';
                            $sdk->setUser(new UserContext(
                                (string) ($u->getAuthIdentifier() ?? ''),
                                (string) ($u->email ?? ''),
                                $autoIp
                            ));
                        }
                        $sdk->captureError($e);
                    } catch (Throwable $inner) {
                        // never break the host app
                    }
                });
                return;
            }
        } catch (Throwable $e) {
            // fall through
        }

        // Fallback: register a global PHP exception handler. Less precise but
        // still captures fatal exceptions in non-Foundation contexts (queue
        // workers without an exception handler binding, etc).
        $sdk->registerErrorHandler();
    }

    /**
     * Registers a Guzzle middleware via {@code Http::globalMiddleware} that wraps
     * every outbound request made through Laravel's {@code Http::} client. The
     * middleware records true round-trip duration (microtime delta around the
     * Guzzle handler call) and captures both successful responses and failed
     * requests (connection refused, timeout, etc).
     *
     * Replaces the older approach of listening to {@code ResponseReceived}
     * which had no timing exposed.
     */
    private function registerOutboundHttpInstrumentation(AllStak $sdk): void
    {
        try {
            Http::globalMiddleware(function (callable $handler) use ($sdk): callable {
                return function (RequestInterface $request, array $options) use ($handler, $sdk) {
                    $start = microtime(true);
                    return $handler($request, $options)->then(
                        function (ResponseInterface $response) use ($request, $start, $sdk) {
                            $this->captureOutbound($sdk, $request, $response, $start);
                            return $response;
                        },
                        function ($reason) use ($request, $start, $sdk) {
                            // Failed outbound (connection refused, timeout, DNS, ...)
                            $this->captureOutbound($sdk, $request, null, $start, $reason);
                            return GuzzleCreate::rejectionFor($reason);
                        }
                    );
                };
            });
        } catch (Throwable $e) {
            // never break boot if Guzzle isn't on the classpath in some weird
            // installation. The Http facade is part of laravel/framework so this
            // path is essentially always available.
        }
    }

    private function captureOutbound(
        AllStak $sdk,
        RequestInterface $request,
        ?ResponseInterface $response,
        float $startMicrotime,
        $error = null
    ): void {
        try {
            $duration = (int) round((microtime(true) - $startMicrotime) * 1000);
            $uri = $request->getUri();
            $statusCode = $response?->getStatusCode() ?? 0;
            $sdk->captureHttpRequest([
                'direction' => 'outbound',
                'method' => $request->getMethod(),
                'host' => $uri->getHost() ?: 'unknown',
                'path' => $uri->getPath() ?: '/',
                'statusCode' => $statusCode,
                'durationMs' => max(0, $duration),
                'requestSize' => $request->getBody()->getSize() ?? 0,
                'responseSize' => $response?->getBody()->getSize() ?? 0,
            ]);
        } catch (Throwable $e) {
            // best effort — never break the host app
        }
    }

    /**
     * Hooks Laravel's scheduler lifecycle events and ships a heartbeat for
     * every scheduled task run. The slug is derived from the task's mutex name
     * (or command/description if mutex is unavailable) and sanitised to match
     * the {@code ^[a-z0-9-]+$} format the AllStak heartbeat ingest expects.
     *
     * Customers get cron monitoring with zero code changes — just defining
     * tasks in {@code routes/console.php} or {@code app/Console/Kernel.php}
     * is enough.
     *
     * Heartbeats are gated by {@code $heartbeat}; breadcrumbs for the
     * starting/finished/failed/skipped lifecycle are always recorded so the
     * scheduler activity shows up on the next captured error regardless.
     */
    private function registerScheduledTaskInstrumentation(AllStak $sdk, bool $heartbeat = true): void
    {
        $handles = [];

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use ($sdk, $heartbeat, &$handles): void {
            try {
                $slug = $this->slugFromTask($event->task);
                if ($slug === null) {
                    return;
                }
                $sdk->addBreadcrumb('default', 'Scheduled task starting: ' . $slug, 'info');
                if ($heartbeat) {
                    $handles[spl_object_id($event->task)] = $sdk->startJob($slug);
                }
            } catch (Throwable $e) {
                // never break the scheduler
            }
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($sdk, &$handles): void {
            try {
                $key = spl_object_id($event->task);
                if (!isset($handles[$key])) {
                    return;
                }
                $sdk->finishJob($handles[$key], 'success');
                unset($handles[$key]);
            } catch (Throwable $e) {
                // best effort
            }
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($sdk, $heartbeat, &$handles): void {
            try {
                $key = spl_object_id($event->task);
                if (!$heartbeat) {
                    $sdk->addBreadcrumb('default', 'Scheduled task failed', 'error');
                    unset($handles[$key]);
                    return;
                }
                if (!isset($handles[$key])) {
                    // Task may have failed before Starting fired (e.g. mutex error).
                    // Send a fresh heartbeat using a synthetic handle.
                    $slug = $this->slugFromTask($event->task);
                    if ($slug === null) {
                        return;
                    }
                    $handle = $sdk->startJob($slug);
                    $sdk->finishJob($handle, 'failed', $event->exception?->getMessage());
                    return;
                }
                $sdk->finishJob($handles[$key], 'failed', $event->exception?->getMessage());
                unset($handles[$key]);
            } catch (Throwable $e) {
                // best effort
            }
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) use (&$handles): void {
            // Drop any handle so it doesn't leak across runs. Skipped tasks
            // intentionally do NOT count as a heartbeat — the cron monitor
            // would otherwise mark them as down.
            try {
                $key = spl_object_id($event->task);
                unset($handles[$key]);
            } catch (Throwable $e) {
                // best effort
            }
        });
    }

    /**
     * Instruments the queue worker lifecycle. Each job runs under a fresh trace
     * with a root span opened on {@code JobProcessing} and finished on
     * {@code JobProcessed} (so the dashboard sees a real per-job duration), plus
     * lifecycle breadcrumbs. {@code JobFailed} / {@code JobExceptionOccurred}
     * promote to captureException. Works for all queue connections (sync,
     * database, redis, sqs). Event classes are referenced via ::class so this
     * registers harmlessly even if the queue component is not installed.
     *
     * @var array<int,string> $jobSpans Active span ids keyed by job object id.
     */
    private function registerQueueInstrumentation(AllStak $sdk): void
    {
        $jobSpans = [];

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($sdk, &$jobSpans): void {
            try {
                $name = $event->job->resolveName();
                $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : '';
                // A queued job is its own unit of work — start a fresh trace so
                // its DB/HTTP/log telemetry is correlated under one job trace and
                // never bleeds into a previous worker iteration.
                $sdk->resetTrace();
                $spanId = $sdk->startSpan('queue.process', $name, [
                    'queue.connection' => (string) $event->connectionName,
                    'queue.name' => $queue,
                    'queue.job' => $name,
                    'queue.attempts' => (string) $event->job->attempts(),
                ]);
                $jobSpans[spl_object_id($event->job)] = $spanId;
                $sdk->addBreadcrumb('default', 'Queue job processing: ' . $name, 'info', [
                    'connection' => $event->connectionName,
                    'queue' => $queue,
                    'attempts' => $event->job->attempts(),
                ]);
            } catch (Throwable $e) {
                // best effort
            }
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) use ($sdk, &$jobSpans): void {
            try {
                $key = spl_object_id($event->job);
                if (isset($jobSpans[$key])) {
                    $sdk->finishSpan($jobSpans[$key], 'ok');
                    unset($jobSpans[$key]);
                }
                $sdk->addBreadcrumb('default', 'Queue job processed: ' . $event->job->resolveName(), 'info', [
                    'connection' => $event->connectionName,
                ]);
                // The job's own trace is complete — reset so the next worker
                // iteration starts clean.
                $sdk->resetTrace();
            } catch (Throwable $e) {
                // best effort
            }
        });

        Event::listen(JobFailed::class, function (JobFailed $event) use ($sdk, &$jobSpans): void {
            try {
                $key = spl_object_id($event->job);
                if (isset($jobSpans[$key])) {
                    $sdk->finishSpan($jobSpans[$key], 'error');
                    unset($jobSpans[$key]);
                }
            } catch (Throwable $e) {
                // best effort
            }
            try {
                $sdk->setGlobalContext(array_merge($sdk->getOptions() ? [] : [], [
                    'queue.job' => $event->job->resolveName(),
                    'queue.connection' => (string) $event->connectionName,
                    'queue.attempts' => (string) $event->job->attempts(),
                ]));
                if ($event->exception !== null) {
                    $sdk->captureError($event->exception, [
                        'queue.job' => $event->job->resolveName(),
                        'queue.connection' => (string) $event->connectionName,
                        'queue.attempts' => (string) $event->job->attempts(),
                        'queue.payload_id' => (string) ($event->job->getJobId() ?? ''),
                    ]);
                } else {
                    $sdk->captureMessage(
                        'Queue job failed: ' . $event->job->resolveName(),
                        'error',
                        ['queue.connection' => $event->connectionName]
                    );
                }
            } catch (Throwable $e) {
                // never break the queue worker
            }
        });

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($sdk): void {
            try {
                if ($event->exception !== null) {
                    $sdk->captureError($event->exception, [
                        'queue.job' => $event->job->resolveName(),
                        'queue.connection' => (string) $event->connectionName,
                        'queue.attempts' => (string) $event->job->attempts(),
                    ]);
                }
            } catch (Throwable $e) {
                // best effort
            }
        });
    }

    /**
     * Instruments Artisan / console commands. Opens a span + breadcrumb on
     * {@code CommandStarting} and finishes it on {@code CommandFinished} with
     * the exit code recorded as a span tag and status. Guarded by class_exists
     * so it no-ops if the console component is somehow absent.
     *
     * @var array<string,string> $commandSpans Active span ids keyed by command.
     */
    private function registerConsoleInstrumentation(AllStak $sdk): void
    {
        $startingEvent = 'Illuminate\\Console\\Events\\CommandStarting';
        $finishedEvent = 'Illuminate\\Console\\Events\\CommandFinished';
        if (!class_exists($startingEvent) || !class_exists($finishedEvent)) {
            return;
        }

        $commandSpans = [];

        Event::listen($startingEvent, function ($event) use ($sdk, &$commandSpans): void {
            try {
                $command = (string) ($event->command ?? 'artisan');
                if ($command === '') {
                    $command = 'artisan';
                }
                // Each command invocation is its own unit of work.
                $sdk->resetTrace();
                $spanId = $sdk->startSpan('console.command', $command, [
                    'console.command' => $command,
                ]);
                $commandSpans[$command] = $spanId;
                $sdk->addBreadcrumb('default', 'Console command starting: ' . $command, 'info');
            } catch (Throwable $e) {
                // never break the command
            }
        });

        Event::listen($finishedEvent, function ($event) use ($sdk, &$commandSpans): void {
            try {
                $command = (string) ($event->command ?? 'artisan');
                if ($command === '') {
                    $command = 'artisan';
                }
                $exitCode = (int) ($event->exitCode ?? 0);
                if (isset($commandSpans[$command])) {
                    $spanId = $commandSpans[$command];
                    $sdk->setSpanTag($spanId, 'console.exit_code', (string) $exitCode);
                    $sdk->finishSpan($spanId, $exitCode === 0 ? 'ok' : 'error');
                    unset($commandSpans[$command]);
                }
                $sdk->addBreadcrumb(
                    'default',
                    'Console command finished: ' . $command . ' (exit ' . $exitCode . ')',
                    $exitCode === 0 ? 'info' : 'error'
                );
            } catch (Throwable $e) {
                // best effort
            }
        });
    }

    /**
     * Instruments cache hits/misses/writes/forgets as breadcrumbs. The cache
     * event classes live in illuminate/cache which is not a hard dependency of
     * this package, so every event name is guarded by class_exists.
     */
    private function registerCacheInstrumentation(AllStak $sdk): void
    {
        $events = [
            'Illuminate\\Cache\\Events\\CacheHit' => 'Cache hit',
            'Illuminate\\Cache\\Events\\CacheMissed' => 'Cache miss',
            'Illuminate\\Cache\\Events\\KeyWritten' => 'Cache key written',
            'Illuminate\\Cache\\Events\\KeyForgotten' => 'Cache key forgotten',
        ];
        foreach ($events as $class => $label) {
            if (!class_exists($class)) {
                continue;
            }
            Event::listen($class, function ($event) use ($sdk, $label): void {
                try {
                    $key = is_object($event) && isset($event->key) ? (string) $event->key : '';
                    $data = [];
                    if ($key !== '') {
                        $data['key'] = $key;
                    }
                    if (is_object($event) && isset($event->storeName) && $event->storeName !== null) {
                        $data['store'] = (string) $event->storeName;
                    }
                    $sdk->addBreadcrumb('default', $label . ($key !== '' ? ': ' . $key : ''), 'debug', $data);
                } catch (Throwable $e) {
                    // best effort
                }
            });
        }
    }

    /**
     * Instruments Redis command execution as breadcrumbs. Requires the Redis
     * component AND command events to be enabled in the host app
     * ({@code Redis::enableEvents()} / {@code 'events' => true}). Guarded by
     * class_exists so it no-ops when Redis is not installed.
     */
    private function registerRedisInstrumentation(AllStak $sdk): void
    {
        $class = 'Illuminate\\Redis\\Events\\CommandExecuted';
        if (!class_exists($class)) {
            return;
        }
        Event::listen($class, function ($event) use ($sdk): void {
            try {
                $command = is_object($event) && isset($event->command) ? (string) $event->command : 'command';
                $timeMs = is_object($event) && isset($event->time) ? (float) $event->time : 0.0;
                $sdk->addBreadcrumb('query', 'Redis ' . $command, 'debug', [
                    'connection' => is_object($event) && isset($event->connectionName) ? (string) $event->connectionName : '',
                    'durationMs' => (int) round($timeMs),
                ]);
            } catch (Throwable $e) {
                // best effort
            }
        });
    }

    /**
     * Light view-render instrumentation: records a breadcrumb when a view is
     * composed. Kept intentionally cheap (breadcrumb only, no span) because
     * views can render in tight loops. Guarded by class_exists on the View
     * factory contract.
     */
    private function registerViewInstrumentation(AllStak $sdk): void
    {
        if (!interface_exists('Illuminate\\Contracts\\View\\Factory')
            && !class_exists('Illuminate\\View\\Factory')) {
            return;
        }
        // 'composing:*' fires for every composed view; the wildcard payload's
        // first element is the View instance.
        Event::listen('composing:*', function ($eventName, $data) use ($sdk): void {
            try {
                $view = is_array($data) ? ($data[0] ?? null) : $data;
                $name = is_object($view) && method_exists($view, 'name') ? (string) $view->name() : '';
                if ($name === '') {
                    return;
                }
                $sdk->addBreadcrumb('ui', 'View composing: ' . $name, 'debug', ['view' => $name]);
            } catch (Throwable $e) {
                // best effort
            }
        });
    }

    /**
     * Livewire component lifecycle breadcrumbs. Livewire is an optional package;
     * its event names differ between v2 ('component.mount') and v3
     * ('livewire:init' style hooks), so we register the broadly available
     * 'component.mount'/'component.hydrate' listeners and guard on the Livewire
     * facade/manager class being present.
     */
    private function registerLivewireInstrumentation(AllStak $sdk): void
    {
        if (!class_exists('Livewire\\Livewire')
            && !class_exists('Livewire\\LivewireManager')) {
            return;
        }
        $hooks = [
            'component.mount' => 'Livewire mount',
            'component.hydrate' => 'Livewire hydrate',
        ];
        foreach ($hooks as $hook => $label) {
            Event::listen($hook, function (...$args) use ($sdk, $label): void {
                try {
                    $component = $args[0] ?? null;
                    $name = is_object($component) && property_exists($component, 'name')
                        ? (string) $component->name
                        : (is_string($component) ? $component : '');
                    $sdk->addBreadcrumb('ui', $label . ($name !== '' ? ': ' . $name : ''), 'info');
                } catch (Throwable $e) {
                    // best effort
                }
            });
        }
    }

    /**
     * When running under Laravel Octane (long-lived workers that handle many
     * requests in one process), reset the SDK trace/scope/buffers at the start
     * of each request so telemetry never leaks across pooled requests. No-op
     * unless the Octane RequestReceived event class is present.
     */
    private function registerOctaneReset(AllStak $sdk): void
    {
        $class = 'Laravel\\Octane\\Events\\RequestReceived';
        if (!class_exists($class)) {
            return;
        }
        Event::listen($class, function () use ($sdk): void {
            try {
                // Drain anything left from the previous request, then clear all
                // per-request state for the incoming one.
                $sdk->flush();
                $sdk->resetTrace();
                $sdk->clearBreadcrumbs();
                $sdk->clearRequestContext();
                $sdk->clearUser();
            } catch (Throwable $e) {
                // never break the worker
            }
        });
    }

    /**
     * Derive a stable {@code ^[a-z0-9-]+$} slug from a Laravel scheduled task.
     * Preference order: explicit name → mutex name → command → description.
     * Returns null if nothing usable can be derived.
     */
    private function slugFromTask($task): ?string
    {
        try {
            // Laravel's Event has a public `description` set via ->name()
            // and computes a mutex from command + expression.
            $candidates = [
                $task->description ?? null,
                method_exists($task, 'mutexName') ? $task->mutexName() : null,
                $task->command ?? null,
            ];
            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }
                $slug = strtolower($candidate);
                $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
                $slug = trim($slug, '-');
                $slug = substr($slug, 0, 64);
                if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug)) {
                    return $slug;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }
        return null;
    }
}
