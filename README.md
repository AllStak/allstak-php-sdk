# AllStak PHP SDK

Drop-in observability for PHP and Laravel apps. Ship errors, logs, HTTP requests, DB queries, traces, and cron heartbeats to AllStak with one Composer package and one API key.

## 1. What you get

**One package. One API key. Zero code changes** for the basics.

After adding the SDK to your Laravel app, every unhandled exception, every inbound HTTP request, every Eloquent/SQL query, every WARN/ERROR/CRITICAL log line, and every outbound `Http::` call is automatically captured and shipped to AllStak. You can keep adding manual capture calls when you want richer context, but you don't have to.

### Call styles

The SDK supports two equivalent call styles. Pick whichever fits your codebase:

```php
// Instance style — call on the singleton returned by init()
$sdk = \AllStak\AllStak::init([...]);
$sdk->captureError($e);
$sdk->setUser($user);
$sdk->setTag('service', 'checkout');
```

```php
// Static style — via the Facade, works from anywhere after init()
use AllStak\Facade as AllStakFacade;

AllStakFacade::captureError($e);
AllStakFacade::captureException($e);     // cross-SDK alias for captureError
AllStakFacade::captureMessage('hello');
AllStakFacade::setUser($user);
AllStakFacade::setTag('service', 'checkout');
AllStakFacade::setContext('region', 'us-east-1');
AllStakFacade::flush();                  // cross-SDK alias for shutdown
```

Both styles hit the same singleton. If `init()` has not been called, all Facade methods are silent no-ops so the SDK can never crash the host app.

**⚠️ Do not call instance methods statically on `AllStak` itself** — `AllStak::setUser(...)` will throw a clear `BadMethodCallException` directing you to either the instance style or the Facade. This is PHP's safety net, not an SDK bug.

## 2. Install

```bash
composer require allstak/sdk-php
```

Requires PHP 8.1+ and the `ext-curl` and `ext-json` extensions. Works with Laravel 10 and 11.

## 3. 60-second setup (Laravel)

Add three lines to your `.env`:

```env
ALLSTAK_API_KEY=ask_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
ALLSTAK_ENVIRONMENT=production
ALLSTAK_RELEASE=v1.0.0
```

That's it. The package auto-discovers via Laravel's `extra.laravel.providers`, the service provider boots the SDK on app start, and registers the request middleware, exception hook, log listener, DB listener and HTTP-client listener for you. Get the API key from your AllStak dashboard → Project → Install SDK.

## 4. First event in under a minute

Boot your app and trigger any error route you have. For example:

```php
Route::get('/boom', function () {
    throw new RuntimeException('hello allstak');
});
```

`curl http://localhost:8000/boom` and open the AllStak dashboard → **Errors** → you'll see the exception with the full stack trace, request method, path, host, trace ID, breadcrumbs of recent log lines and HTTP entries, and the linked trace.

If you want to send a manual event from anywhere in your code:

```php
use AllStak\AllStak;

AllStak::getInstance()?->captureMessage('hello from PHP', 'info', ['source' => 'cli']);
```

## 5. Plain PHP (no Laravel)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use AllStak\AllStak;

AllStak::init([
    'apiKey'      => 'ask_live_xxx',
    'environment' => 'production',
    'release'     => 'v1.0.0',
]);

// Optional: send unhandled exceptions and PHP errors automatically.
AllStak::getInstance()?->registerErrorHandler();

try {
    doWork();
} catch (\Throwable $e) {
    AllStak::getInstance()?->captureError($e);
}
```

The SDK auto-flushes its buffers on `register_shutdown_function`. Call `AllStak::getInstance()?->flush()` if you need a manual drain.

## 6. What gets captured automatically (Laravel)

| Feature | Source | Default |
|---|---|---|
| Unhandled exceptions | Hooked into the Laravel exception handler via `reportable()` (auto-attaches `Auth::user()`) | ✅ on |
| Inbound HTTP requests | `AllStakRequestMiddleware` (auto-prepended) | ✅ on |
| Logs (`debug`/`info`/`warning`/`error`/`critical`) | Listener on `Illuminate\Log\Events\MessageLogged` | ✅ on |
| DB queries (Eloquent + raw) | `DB::listen` callback into `DatabaseMonitor` | ✅ on |
| Outbound HTTP via Laravel `Http` client | Guzzle middleware via `Http::globalMiddleware()` — true round-trip timing, success + failure | ✅ on |
| Scheduled tasks (`Schedule::call`, `Schedule::command`) | Listener on `ScheduledTaskStarting` / `Finished` / `Failed` — auto-creates a cron monitor per task name | ✅ on |
| Per-request trace span | `startSpan` in middleware → `finishSpan` after response | ✅ on |
| Auto breadcrumbs | Last log + HTTP entries attached to the next captured error | ✅ on |
| Manual cron heartbeats | `AllStak::getInstance()->startJob() / finishJob()` | manual |

Each capture can be turned off via `.env`:
```env
ALLSTAK_CAPTURE_REQUESTS=false
ALLSTAK_CAPTURE_EXCEPTIONS=false
ALLSTAK_CAPTURE_LOGS=false
ALLSTAK_CAPTURE_DB=false
ALLSTAK_CAPTURE_HTTP_CLIENT=false
ALLSTAK_CAPTURE_SCHEDULED_TASKS=false
```

**Self-hosted AllStak / integration tests** — set `ALLSTAK_HOST=https://your-allstak.example.com` to override the static production ingest URL. Production customers leave this unset.

## 7. Manual capture

```php
use AllStak\AllStak;
use AllStak\Models\UserContext;

$sdk = AllStak::getInstance();

// Errors with metadata
$sdk?->captureError($exception, [
    'metadata' => ['orderId' => 'ORD-123', 'amount' => 99.9],
]);

// Messages / arbitrary log entries
$sdk?->captureMessage('Order processed', 'info', ['orderId' => 'ORD-123']);
$sdk?->captureLog('warn', 'Retrying payment', ['attempt' => 2]);

// Per-process user context
$sdk?->setUser(new UserContext('user-42', 'alice@example.com', '10.0.0.1'));

// Global tags attached to every event
$sdk?->setGlobalContext(['service' => 'checkout', 'tier' => 'web']);

// Breadcrumbs (attached to the next captured error)
$sdk?->addBreadcrumb('ui',   'User clicked Pay');
$sdk?->addBreadcrumb('http', 'POST /api/payments -> 502', 'error', ['statusCode' => 502]);

// Cron heartbeats — auto-creates the monitor on first ping
$handle = $sdk->startJob('daily-report');
try {
    runReport();
    $sdk->finishJob($handle, 'success', '42 rows processed');
} catch (\Throwable $e) {
    $sdk->finishJob($handle, 'failed', $e->getMessage());
    throw $e;
}
```

## 8. Where to find your data in the dashboard

| What you sent | Dashboard page |
|---|---|
| Exceptions (auto + `captureError`) | **Errors** |
| Log lines (auto Laravel `Log::*` + `captureLog`) | **Logs** |
| Inbound + outbound HTTP requests | **Requests** |
| SQL queries with normalized SQL | **Database** |
| Per-request trace spans | **Traces** |
| Cron heartbeats (`startJob` / `finishJob`) | **Cron Jobs** |

Click any error to see: full stack trace with cause chain, breadcrumbs, request context (method/path/host/trace ID), user info, custom metadata as tags, occurrence count, fingerprint, release, environment, and the linked trace.

## 9. Production notes

- **Buffering**: logs, HTTP requests and DB queries are batched in per-channel ring buffers (default 500 items) and flushed every 5 s, when a buffer hits 80%, or when Laravel terminates the request. Errors and cron heartbeats are sent immediately.
- **Retries**: 5 attempts with exponential backoff for `5xx` and network errors. `4xx` events are dropped (they're a payload bug, not a transient failure). A `401` permanently disables the SDK in-process so a bad key never floods the backend.
- **Timeouts**: 3 s connect, 5 s total — never blocks your hot path for long.
- **Sensitive data masking**: keys named `password`, `token`, `secret`, `authorization`, `api_key`, `apikey`, `cookie`, `set-cookie`, `x-api-key`, `x-auth-token` are automatically replaced with `[MASKED]` in metadata. Sensitive query params like `?token=` and `?apikey=` are stripped from captured paths.
- **Static ingest host**: the SDK ships with the production ingest URL baked in (`AllStak\Config\Options::INGEST_HOST`). There is no DSN, no host config in `.env`, and no URL for customers to manage. Customers only need an API key.
- **No-op safe**: if `ALLSTAK_API_KEY` is empty, the service provider does nothing — your app boots normally without any errors. Local dev and CI can leave the key unset.
- **Termination flush**: the service provider hooks `app->terminating(...)` to flush all buffers before Laravel sends the response, so you don't lose events on short-lived workers.

## 10. Real Laravel example

`.env`:
```env
APP_NAME=checkout-api
APP_ENV=production
ALLSTAK_API_KEY=ask_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
ALLSTAK_RELEASE=v1.4.2
```

`app/Http/Controllers/CheckoutController.php`:
```php
<?php

namespace App\Http\Controllers;

use AllStak\AllStak;
use AllStak\Models\UserContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function process(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            AllStak::getInstance()?->setUser(new UserContext(
                (string) $user->id, $user->email, $request->ip()
            ));
        }

        AllStak::getInstance()?->addBreadcrumb('ui', "Customer clicked checkout for {$orderId}");

        try {
            Log::info('Processing checkout', ['orderId' => $orderId]);
            $charge = Http::timeout(10)->post('https://api.stripe.com/v1/charges', [...]);
            // outbound HTTP request is automatically captured
            return response()->json(['ok' => true, 'charge' => $charge->json()]);
        } catch (\Throwable $e) {
            // The unhandled exception will be auto-captured by Laravel's
            // exception handler. The manual call below adds richer metadata.
            AllStak::getInstance()?->captureError($e, [
                'metadata' => ['orderId' => $orderId, 'stage' => 'checkout'],
            ]);
            throw $e;
        }
    }
}
```

That's the entire integration. The starter handles the rest.

## 11. Troubleshooting

**Events aren't appearing in the dashboard.**
1. Is the SDK initialised? Set `ALLSTAK_DEBUG=true` and tail your Laravel log. You should see `AllStak SDK initialized {host:..., environment:..., release:...}` once per request boot.
2. Is the API key correct? A `401` from the ingest endpoint causes the SDK to log `Invalid API key — disabling SDK` and stop sending. Get a fresh key from the dashboard.
3. Is the project context right in the dashboard? Use the project picker in the top bar — events show up under the project that owns the API key, not necessarily the one you have selected.
4. Did you check the right environment filter? The dashboard defaults to "All Envs"; if you set `ALLSTAK_ENVIRONMENT=staging`, events show under `staging`.

**`401 INVALID_API_KEY`.** The key in `ALLSTAK_API_KEY` doesn't match a project. Copy the key from the dashboard's "Install SDK" step.

**Wrong project receiving events.** API keys are project-scoped. Confirm in **Settings → API Keys** which project owns your key.

**Dashboard is empty even though logs say 202.** You're looking at the wrong project or environment filter. Switch the project picker in the top bar, then click "All Envs" / "Last 24h".

**Laravel config cache is stale.** After changing `.env`, run `php artisan config:clear` (and `config:cache` if you cache config in production). The SDK reads `config('allstak.*')` at boot; cached config from before the env change would still be no-op.

**Queue worker not capturing.** Long-running queue workers boot the service provider once, so the SDK is available — but each job's exceptions are reported to Laravel's exception handler. The SDK's `reportable()` callback fires for every reported exception. If your worker uses `Queue::failing()` instead of letting Laravel report, call `AllStak::getInstance()?->captureError($e)` manually inside that callback.

**SDK not booting.** Make sure `ALLSTAK_API_KEY` is set in `.env` and run `php artisan package:discover --ansi`. You should see `allstak/sdk-php ... DONE` in the output.

**Where's the host config?** There isn't one for normal customers. The ingest URL is hardcoded in `Options::INGEST_HOST` (`https://api.allstak.sa`). For self-hosted AllStak deployments or integration tests, set `ALLSTAK_HOST=https://your-allstak.example.com` in your `.env`.

## 12. License

MIT
