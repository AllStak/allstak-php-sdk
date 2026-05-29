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
    | Every collector below is ON by default and wired by the service provider.
    | Each is guarded so that if the relevant framework piece is not installed
    | (e.g. you don't use the queue, cache, Redis, or Octane), it degrades to a
    | silent no-op. Flip any of these to false (or set the matching ALLSTAK_*
    | env var) to disable a single collector without touching the others.
    */
    'capture_requests'        => (bool) env('ALLSTAK_CAPTURE_REQUESTS', true),
    'capture_exceptions'      => (bool) env('ALLSTAK_CAPTURE_EXCEPTIONS', true),
    'capture_logs'            => (bool) env('ALLSTAK_CAPTURE_LOGS', true),
    'capture_db'              => (bool) env('ALLSTAK_CAPTURE_DB', true),
    'capture_http_client'     => (bool) env('ALLSTAK_CAPTURE_HTTP_CLIENT', true),
    'capture_scheduled_tasks' => (bool) env('ALLSTAK_CAPTURE_SCHEDULED_TASKS', true),
    'capture_queue'           => (bool) env('ALLSTAK_CAPTURE_QUEUE', true),
    'capture_console'         => (bool) env('ALLSTAK_CAPTURE_CONSOLE', true),
    'capture_cache'           => (bool) env('ALLSTAK_CAPTURE_CACHE', true),
    'capture_redis'           => (bool) env('ALLSTAK_CAPTURE_REDIS', true),
    'capture_views'           => (bool) env('ALLSTAK_CAPTURE_VIEWS', true),
    'capture_livewire'        => (bool) env('ALLSTAK_CAPTURE_LIVEWIRE', true),

    /*
    |--------------------------------------------------------------------------
    | Scheduled-task heartbeats
    |--------------------------------------------------------------------------
    | When true (and capture_scheduled_tasks is on), every scheduled task run
    | emits a cron heartbeat keyed by a stable slug derived from the task.
    | Disable if you only want scheduler breadcrumbs/spans without heartbeats.
    */
    'scheduled_task_heartbeat' => (bool) env('ALLSTAK_SCHEDULED_TASK_HEARTBEAT', true),

    /*
    |--------------------------------------------------------------------------
    | Octane request isolation
    |--------------------------------------------------------------------------
    | When running under Laravel Octane (long-lived workers), reset the SDK
    | trace/scope/buffers between pooled requests so telemetry never leaks from
    | one request into the next. No-op when Octane is not installed.
    */
    'octane_reset' => (bool) env('ALLSTAK_OCTANE_RESET', true),
];
