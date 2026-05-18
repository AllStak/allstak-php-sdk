# Changelog

All notable changes to `allstak/sdk-php` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] — 2026-05-17

### Changed
- `Options::VERSION` bumped to `1.2.1` so the runtime constant, this CHANGELOG entry, and the `User-Agent` stamp all agree. Closes the 1.0.0 / 1.2.0 version drift documented in the 2026-05-17 audit.
- `Privacy\Sanitizer`:
  - `SENSITIVE_HEADERS` extended with `proxy-authorization`, `set-cookie`, `x-access-token`.
  - `SENSITIVE_QUERY_PATTERNS` extended with `csrf`, `session`.
  - `SENSITIVE_METADATA_KEYS` extended with `passwd`, `cookie`, `csrf`, `session_id`, `sessionid`.
  - `maskMetadata` now **recurses into nested arrays** so JSON-style payloads are fully redacted.

### Added
- `AllStak::shutdown()` now calls `fastcgi_finish_request()` first when available so the HTTP response is on the wire before the blocking drain runs. PHP-FPM workers are no longer held for the duration of the buffer flush. CLI / Octane / Swoole runtimes are unaffected because the function does not exist there. Internal drain logic moved to `drainShutdownBuffers()`.
- Version consistency test (`tests/Unit/VersionTest.php`) asserts `Options::VERSION` is non-empty and matches the CHANGELOG's top entry.

### Live certification
- 2026-05-17 live ingest run against `https://api.allstak.sa` accepted `captureError` and returned event IDs (`1456c5f7-19e0-4364-82c4-4d037475b8dc`, `d801e98e-ee60-4292-9cc0-38338e7491f2`). Sensitive metadata (`authorization`, `stripe_api_key`, nested `password`, `csrf`) was redacted to `[MASKED]` on the wire; safe metadata (`order_id`, nested `city`) was preserved.

## [1.0.0] — 2026-04-11

First public release of the AllStak PHP SDK on Packagist.

### Highlights

- **Drop-in Laravel integration.** One Composer require, one `ALLSTAK_API_KEY` in `.env`, zero code changes for the basics. The package is auto-discovered via `extra.laravel.providers`.
- **Static ingest host.** Customers no longer pass a host or DSN — the SDK ships with the production AllStak ingest URL baked in (`AllStak\Config\Options::INGEST_HOST`).
- **Real-world validated.** End-to-end validated against a real Laravel 13 Tasks app with real session auth, real CRUD, real exception flows, and real scheduled tasks.

### Added

- **Laravel package** (`AllStak\Laravel\AllStakServiceProvider`):
  - Boots the SDK from `config/allstak.php` (publishable) on Laravel app start.
  - Prepends `AllStak\Laravel\AllStakRequestMiddleware` onto the HTTP kernel to capture every inbound request, set the per-request context, open a root trace span, and auto-attach the authenticated user.
  - Hooks Laravel's `Foundation\Exceptions\Handler::reportable()` so unhandled exceptions are captured automatically with the authenticated user attached.
  - Listens on `Illuminate\Log\Events\MessageLogged` to ship every Laravel `Log::*` call as a structured log entry.
  - Registers a `DB::listen` callback to capture every Eloquent / raw SQL query with normalized SQL.
  - Registers a Guzzle middleware via `Http::globalMiddleware()` to capture every outbound `Http::` call **with true round-trip timing** (success and failure).
  - Listens on the Laravel scheduler lifecycle events (`ScheduledTaskStarting`, `ScheduledTaskFinished`, `ScheduledTaskFailed`, `ScheduledTaskSkipped`) to **auto-instrument scheduled tasks as cron heartbeats** — no manual `startJob`/`finishJob` calls needed.
  - Hooks `app->terminating(...)` to flush all SDK buffers before Laravel sends the response.
  - Five `ALLSTAK_CAPTURE_*` env flags to disable individual capture channels.
- **`Options::VERSION`** constant exposing the SDK version string.
- **`config/allstak.php`** publishable config file with sensible defaults pulled from `APP_NAME` / `APP_ENV`.

### Changed

- **`Options::INGEST_HOST`** is now `https://api.allstak.sa`. To self-host AllStak or run integration tests against a local backend, set `ALLSTAK_HOST=http://localhost:8080` (or wherever) in your Laravel `.env` — the provider will pass that through to `AllStak::init()`.
- **`Options` constructor** no longer requires a `host` field. The optional `host` config key is accepted only for tests / self-hosted setups; passing nothing defaults to `INGEST_HOST`.
- **Removed** the `production HTTPS required` check in `Options` — superseded by the static `INGEST_HOST` policy.

### Fixed

- **Outbound HTTP timing** is now real round-trip duration. The previous Laravel integration listened on `Http\Client\Events\ResponseReceived`, which exposes no timing — every captured outbound row landed on the dashboard with `durationMs=0`. The new implementation wraps the Guzzle handler chain via `Http::globalMiddleware()` and times the actual `$handler($request, $options)` call with `microtime(true)` deltas. Both successful responses **and** failed requests (connection refused, timeout, DNS, etc.) are captured.
- **Authenticated user auto-attach.** Error events now include the authenticated user (`UserContext` with id / email / ip) automatically. The provider attaches the user inside the `reportable()` callback at error-report time, when Laravel's session middleware has already loaded the auth state.

### Laravel customers — what's new

If you're upgrading from an earlier internal version: **set `ALLSTAK_API_KEY` and remove any old `ALLSTAK_HOST=...` line from your `.env`** (unless you self-host AllStak). Everything else is automatic. After upgrading you should immediately see:

- Real `durationMs` on outbound HTTP rows (Requests page)
- Cron heartbeats for every `Schedule::call(...)` and `Schedule::command(...)` (Cron Jobs page)
- The authenticated user's email on every error in the Errors detail page

No code changes are required.

### Known limitations

- Laravel `ValidationException`, `AuthorizationException`, and `ModelNotFoundException` are intentionally not reported by Laravel's `Foundation\Exceptions\Handler` (they're 4xx user-input cases) and therefore do not appear on the Errors page. They are still visible on the Requests page (as 4xx rows) and on the Logs page if you log them. This is correct framework behavior, not an SDK gap.
- Laravel queue workers boot the service provider once per worker process. The SDK still captures per-job exceptions via the same `reportable()` hook; high-throughput workers may want to call `AllStak::getInstance()?->flush()` after each job to keep buffers small.

### Compatibility

- PHP 8.1+
- Laravel 10.x and 11.x (auto-discovered)
- Plain PHP supported via the `AllStak::init()` static facade

### Pre-1.0 work

Internal pre-1.0 development happened across the `allstak/sdk-php` repository before this public release. There were no published Packagist versions prior to `1.0.0`.
