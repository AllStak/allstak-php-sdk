<?php
require_once __DIR__ . '/../vendor/autoload.php';

use AllStak\AllStak;
use AllStak\Facade;
use AllStak\Models\UserContext;

AllStak::reset();
AllStak::init([
    'apiKey'      => getenv('ALLSTAK_API_KEY'),
    'host'        => getenv('ALLSTAK_HOST') ?: 'http://localhost:8080',
    'environment' => 'development',
    'release'     => 'php-static-style@1.0.0',
    'debug'       => false,
]);

echo "Facade setUser / setTag / setContext...\n";
Facade::setUser(new UserContext(id: 'php-facade-user', email: 'facade@test.com'));
Facade::setTag('service', 'allstak-php-facade');
Facade::setContext('region', 'us-east-1');

echo "Facade captureMessage...\n";
Facade::captureMessage('PHP Facade::captureMessage works', 'info');

echo "Facade captureException alias...\n";
try { throw new \RuntimeException('PHP Facade::captureException alias works'); }
catch (\Throwable $e) { Facade::captureException($e); }

echo "Facade captureError...\n";
try { throw new \LogicException('PHP Facade::captureError works'); }
catch (\Throwable $e) { Facade::captureError($e); }

echo "Facade flush...\n";
Facade::flush();
echo "DONE\n";
