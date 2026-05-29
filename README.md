# AllStak PHP SDK

AllStak SDK for PHP, Laravel, and Guzzle. Captures errors, logs, inbound and outbound HTTP requests, PDO telemetry, and cron heartbeats.

## Install

```bash
composer require allstak/sdk-php
```

## Setup

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use AllStak\AllStak;
use AllStak\Facade;

AllStak::init([
    'apiKey' => getenv('ALLSTAK_API_KEY'),
    'environment' => getenv('APP_ENV') ?: 'production',
    'release' => getenv('ALLSTAK_RELEASE'),
    'service' => 'checkout-api',
]);

Facade::captureError(new RuntimeException('checkout failed'));
Facade::captureLog('info', 'payment retry', ['orderId' => 'ord_123']);
```

## Laravel

The package auto-discovers its service provider. After `composer require` and
setting one API key, the app is fully instrumented with no code changes.

Register the service provider manually only if your Laravel version does not
auto-discover packages:

```php
AllStak\Laravel\AllStakServiceProvider::class,
```

Add environment variables:

```bash
ALLSTAK_API_KEY=ask_live_xxx
ALLSTAK_ENVIRONMENT=production
ALLSTAK_RELEASE=myapp@1.0.0
```

### What is captured automatically

Every collector is ON by default, guarded so missing framework pieces degrade
gracefully, and individually toggleable via `config/allstak.php` or an env var:

| Collector | Env toggle | Captures |
| --- | --- | --- |
| Exceptions | `ALLSTAK_CAPTURE_EXCEPTIONS` | Unhandled exceptions via the framework exception handler |
| Inbound HTTP | `ALLSTAK_CAPTURE_REQUESTS` | Request span + http-request record + request context + authed user |
| Logs | `ALLSTAK_CAPTURE_LOGS` | Log lines; logged throwables at error+ promote to a captured error |
| DB queries | `ALLSTAK_CAPTURE_DB` | Normalized query, type, duration, connection |
| Outbound HTTP | `ALLSTAK_CAPTURE_HTTP_CLIENT` | `Http::` client requests + spans + trace propagation |
| Queue jobs | `ALLSTAK_CAPTURE_QUEUE` | Per-job span + breadcrumbs; failures captured as errors |
| Console / Artisan | `ALLSTAK_CAPTURE_CONSOLE` | Command span + breadcrumb + exit code |
| Scheduled tasks | `ALLSTAK_CAPTURE_SCHEDULED_TASKS` | Lifecycle breadcrumbs + cron heartbeats |
| Cache | `ALLSTAK_CAPTURE_CACHE` | Hit / miss / write / forget breadcrumbs |
| Redis | `ALLSTAK_CAPTURE_REDIS` | Command breadcrumbs (requires Redis events enabled) |
| Views | `ALLSTAK_CAPTURE_VIEWS` | View composing breadcrumbs |
| Livewire | `ALLSTAK_CAPTURE_LIVEWIRE` | Component lifecycle breadcrumbs |

Publish the config to tune any of these:

```bash
php artisan vendor:publish --tag=allstak-config
```

Running under Laravel Octane? The SDK resets its per-request trace/scope/buffers
between pooled requests automatically (`ALLSTAK_OCTANE_RESET`).

## Guzzle

Use the provided middleware to capture outbound HTTP requests when available in your app setup.

## Configuration

| Option | Description |
| --- | --- |
| `apiKey` | Project API key. |
| `environment` | Deployment environment. |
| `release` | App version or commit SHA. |
| `service` | Logical service name. |
| `flushIntervalMs` | Background flush interval. |
| `bufferSize` | Max buffered events. |

## Notes

`AllStak::init([...])` initializes the singleton. For captures, use `AllStak\Facade` or the instance returned by `AllStak::init(...)`.

## Privacy

The SDK redacts common sensitive headers and fields. Avoid putting secrets in custom metadata.

## Troubleshooting

- No events: confirm `ALLSTAK_API_KEY` is set in the PHP runtime.
- Laravel events missing: clear config cache after changing environment variables.
- Short-lived command: flush before process exit when possible.

## Contributing and Support

- Report bugs with the GitHub bug report template: https://github.com/AllStak/allstak-php-sdk/issues/new/choose
- Open pull requests using the checklist in [CONTRIBUTING.md](CONTRIBUTING.md).
- Report security vulnerabilities privately through [SECURITY.md](SECURITY.md).

## License

MIT
