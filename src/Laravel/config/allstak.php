<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    | Get this from your AllStak dashboard → Project → Install SDK.
    | The SDK is a no-op when this is empty (so a missing key never breaks
    | local dev or CI).
    */
    'api_key' => env('ALLSTAK_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Ingest host (optional override)
    |--------------------------------------------------------------------------
    | Leave blank to use the production AllStak ingest endpoint baked into the
    | SDK (Options::INGEST_HOST). Set this only if you self-host AllStak or
    | you're running the SDK against a local backend for integration tests.
    */
    'host' => env('ALLSTAK_HOST', ''),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    | Defaults to APP_ENV (production / staging / local).
    */
    'environment' => env('ALLSTAK_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    | Optional: a release / build identifier (git SHA, semver, etc).
    */
    'release' => env('ALLSTAK_RELEASE', ''),

    /*
    |--------------------------------------------------------------------------
    | Service name
    |--------------------------------------------------------------------------
    | Defaults to APP_NAME.
    */
    'service' => env('ALLSTAK_SERVICE', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Verbose SDK debug logging
    |--------------------------------------------------------------------------
    */
    'debug' => (bool) env('ALLSTAK_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Auto breadcrumbs
    |--------------------------------------------------------------------------
    */
    'auto_breadcrumbs' => (bool) env('ALLSTAK_AUTO_BREADCRUMBS', true),

    /*
    |--------------------------------------------------------------------------
    | What to capture automatically
    |--------------------------------------------------------------------------
    */
    'capture_requests'        => (bool) env('ALLSTAK_CAPTURE_REQUESTS', true),
    'capture_exceptions'      => (bool) env('ALLSTAK_CAPTURE_EXCEPTIONS', true),
    'capture_logs'            => (bool) env('ALLSTAK_CAPTURE_LOGS', true),
    'capture_db'              => (bool) env('ALLSTAK_CAPTURE_DB', true),
    'capture_http_client'     => (bool) env('ALLSTAK_CAPTURE_HTTP_CLIENT', true),
    'capture_scheduled_tasks' => (bool) env('ALLSTAK_CAPTURE_SCHEDULED_TASKS', true),
    'capture_queue'           => (bool) env('ALLSTAK_CAPTURE_QUEUE', true),
];
