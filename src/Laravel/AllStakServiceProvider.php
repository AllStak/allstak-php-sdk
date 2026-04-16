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
                try {
                    $sdk->captureLog($level, (string) $event->message, is_array($event->context) ? $event->context : []);
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
            $this->registerScheduledTaskInstrumentation($sdk);
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
                            $sdk->setUser(new UserContext(
                                (string) ($u->getAuthIdentifier() ?? ''),
                                (string) ($u->email ?? ''),
                                (string) (request()?->ip() ?? '')
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
     */
    private function registerScheduledTaskInstrumentation(AllStak $sdk): void
    {
        $handles = [];

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use ($sdk, &$handles): void {
            try {
                $slug = $this->slugFromTask($event->task);
                if ($slug === null) {
                    return;
                }
                $handles[spl_object_id($event->task)] = $sdk->startJob($slug);
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

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($sdk, &$handles): void {
            try {
                $key = spl_object_id($event->task);
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
