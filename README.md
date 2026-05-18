# allstak/sdk-php

**Drop-in error + log capture for PHP. Works with Laravel, Symfony, and vanilla PHP.**

[![Packagist Version](https://img.shields.io/packagist/v/allstak/sdk-php.svg)](https://packagist.org/packages/allstak/sdk-php)
[![CI](https://github.com/AllStak/allstak-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/AllStak/allstak-php-sdk/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Official AllStak SDK for PHP — captures exceptions, structured logs, HTTP requests, database queries, distributed traces, and cron heartbeats for plain PHP and Laravel applications.

## Dashboard

View captured events live at [app.allstak.sa](https://app.allstak.sa).

![AllStak dashboard](https://app.allstak.sa/images/dashboard-preview.png)

## Features

- `set_exception_handler` + `set_error_handler` capture
- Laravel service provider with middleware auto-registration
- PDO / database query telemetry
- Guzzle and native `http` outbound request capture
- Structured logs with ring-buffered breadcrumbs
- Distributed tracing with span stack
- Cron heartbeats and feature flags

## What You Get

Once integrated, every event flows to your AllStak dashboard:

- **Errors** — stack traces, breadcrumbs, release + environment tags
- **Logs** — structured logs with search and filters
- **HTTP** — outbound Guzzle and native `http` timing, status codes, failed calls
- **Database** — PDO query capture with statement normalization
- **Cron monitors** — scheduled job success/failure tracking
- **Alerts** — email and webhook notifications on regressions

## Installation

```bash
composer require allstak/sdk-php
```

## Quick Start

> Create a project at [app.allstak.sa](https://app.allstak.sa) to get your API key.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use AllStak\AllStak;
use AllStak\Facade;

// Initialise the singleton (returns the SDK instance for fluent use).
AllStak::init([
    'apiKey'      => getenv('ALLSTAK_API_KEY'),
    'environment' => 'production',
    'release'     => 'myapp@1.0.0',
]);

// Capture an exception. Use the static facade…
Facade::captureError(new \RuntimeException('test: hello from allstak-php'));

// …or call captureError on the singleton:
// AllStak::getInstance()->captureError(new \RuntimeException('test'));
```

Run the file — the test error appears in your dashboard within seconds.

> **Note:** `AllStak::init([...])` is the only valid static call on the
> `AllStak\AllStak` class. All capture methods are instance methods —
> use `\AllStak\Facade::captureError(...)` (recommended) or call
> `captureError` on the instance returned by `init()`. The method name is
> `captureError` (the facade exposes `captureException` as a cross-SDK alias).

## Get Your API Key

1. Sign up at [app.allstak.sa](https://app.allstak.sa)
2. Create a project
3. Copy your API key from **Project Settings → API Keys**
4. Export it as `ALLSTAK_API_KEY` or pass it to `AllStak::init([...])`

## Configuration

| Option | Type | Required | Default | Description |
|---|---|---|---|---|
| `apiKey` | `string` | yes | — | Project API key (`ask_live_…`) |
| `host` | `string` | no | `https://api.allstak.sa` | Ingest host override |
| `environment` | `string` | no | — | Deployment env |
| `release` | `string` | no | — | Version / release tag |
| `flushIntervalMs` | `int` | no | `5000` | Background flush cadence |
| `bufferSize` | `int` | no | `500` | Max items per buffer |
| `autoBreadcrumbs` | `bool` | no | `true` | Auto-wire breadcrumbs |
| `maxBreadcrumbs` | `int` | no | `50` | Ring buffer size |
| `debug` | `bool` | no | `false` | Verbose SDK logging |

## Example Usage

Capture an exception with metadata:

```php
\AllStak\Facade::captureError($e, ['metadata' => ['orderId' => 'ORD-42']]);
```

Send a structured log:

```php
\AllStak\Facade::captureLog('info', 'Order processed', ['orderId' => 'ORD-123']);
```

Set user and tag:

```php
\AllStak\Facade::setUser(new \AllStak\Models\UserContext('u_42', 'alice@example.com'));
\AllStak\Facade::setTag('region', 'eu-west-1');
```

> Sensitive fields like `password`, `token`, `cookie`, `bearer`,
> `api_key`, `authorization`, and `credit_card` are automatically
> scrubbed on the server side via the canonical denylist sanitizer
> before any event row is persisted — no client configuration required.

### Laravel

Register the provider in `config/app.php` (or via package auto-discovery) and set `ALLSTAK_API_KEY` in your `.env`. Inbound request capture, log channel, and Eloquent query hooks install automatically.

## Production Endpoint

Production endpoint: `https://api.allstak.sa`. Override via `host` for self-hosted deployments:

```php
AllStak::init([
    'apiKey' => getenv('ALLSTAK_API_KEY'),
    'host'   => 'https://allstak.mycorp.com',
]);
```

## Links

- Documentation: https://docs.allstak.sa
- Dashboard: https://app.allstak.sa
- Source: https://github.com/AllStak/allstak-php-sdk

## License

MIT © AllStak
