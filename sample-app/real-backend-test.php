<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AllStak\AllStak;
use AllStak\Models\UserContext;

AllStak::reset();
$sdk = AllStak::init([
    'apiKey'      => getenv('ALLSTAK_API_KEY'),
    'host'        => getenv('ALLSTAK_HOST') ?: 'http://localhost:8080',
    'environment' => 'development',
    'release'     => 'php-sdk-validation@1.0.0',
    'debug'       => false,
]);

$sdk->setUser(new UserContext(id: 'php-test-user', email: 'php@test.com'));
$sdk->setGlobalContext(['service' => 'allstak-php-validation', 'region' => 'us-east-1']);

echo "1. captureMessage...\n";
$sdk->captureMessage('Hello from PHP SDK validation', 'info');

echo "2. captureError handled...\n";
try { throw new \RuntimeException('PHP SDK handled exception test'); }
catch (\Throwable $e) { $sdk->captureError($e, ['scenario' => 'handled']); }

echo "3. captureError fatal...\n";
try { throw new \InvalidArgumentException('PHP SDK fatal exception test'); }
catch (\Throwable $e) { $sdk->captureError($e, ['scenario' => 'fatal']); }

echo "4. captureLog batch...\n";
$sdk->captureLog('info', 'PHP info log', ['request_id' => 'req-1']);
$sdk->captureLog('warning', 'PHP warning log', ['request_id' => 'req-2']);
$sdk->captureLog('error', 'PHP error log', ['request_id' => 'req-3']);

echo "5. captureHttpRequest...\n";
$sdk->captureHttpRequest([
    'method' => 'GET',
    'url' => 'https://api.example.com/items',
    'statusCode' => 200,
    'durationMs' => 123,
    'requestSize' => 0,
    'responseSize' => 456,
]);

echo "Shutdown...\n";
$sdk->shutdown();
echo "DONE\n";
