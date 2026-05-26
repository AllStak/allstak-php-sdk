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

Register the service provider if your Laravel version does not auto-discover packages:

```php
AllStak\Laravel\AllStakServiceProvider::class,
```

Add environment variables:

```bash
ALLSTAK_API_KEY=ask_live_xxx
ALLSTAK_ENVIRONMENT=production
ALLSTAK_RELEASE=myapp@1.0.0
```

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

## License

MIT
