<?php

/**
 * AllStak SDK sample app configuration.
 *
 * For real integration testing, set ALLSTAK_API_KEY and ALLSTAK_HOST
 * environment variables. The sample app can also run against a mock
 * server (see mock-server.php).
 */

return [
    'apiKey'       => getenv('ALLSTAK_API_KEY') ?: 'allstak_live_test_sample_key',
    'host'         => getenv('ALLSTAK_HOST') ?: 'http://localhost:8765',
    'environment'  => 'development',
    'release'      => 'v1.0.0-sample',
    'debug'        => true,
    'maxRetries'   => 2,
    'connectTimeoutMs' => 2000,
    'totalTimeoutMs'   => 3000,
];
