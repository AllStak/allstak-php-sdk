<?php

declare(strict_types=1);

/**
 * Shared smoke script — boots Illuminate\Container with our ServiceProvider,
 * registers the AllStak SDK, exercises capture paths with sensitive metadata,
 * and asserts the Sanitizer redacts the canary value.
 *
 * Runs against any Laravel major (9/10/11/12) because we depend only on
 * illuminate/contracts container + ServiceProvider abstract — same surface in
 * every version.
 */

// __DIR__ resolves to the symlink target dir, not the fixture dir. Use the
// current working directory the runner cd'd into instead.
$fixtureRoot = getcwd();
require $fixtureRoot . '/vendor/autoload.php';

use AllStak\AllStak;
use AllStak\Config\Options;
use AllStak\Laravel\AllStakServiceProvider;
use AllStak\Privacy\Sanitizer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;

// Reset SDK singleton in case prior fixtures ran in the same process.
AllStak::reset();

$container = new FoundationApplication($fixtureRoot);
// Minimal bindings the ServiceProvider needs (skip kernel/middleware install
// since we're not running an HTTP cycle).
$container->instance('env', 'testing');
$container->instance('config', new \Illuminate\Config\Repository());
$container->detectEnvironment(fn () => 'testing');
// Avoid binding HttpKernel so the provider's middleware-prepend block is a
// no-op via the make() guard further down (capture_requests defaults off in
// our fixture config).
// config/* is set by the provider via mergeConfigFrom + env(...) lookups —
// no need to pre-seed beyond ALLSTAK_* env vars below.

// Stamp a fake env so capture flags are deterministic.
foreach ([
    'ALLSTAK_API_KEY'        => 'ask_test_fixture',
    'ALLSTAK_HOST'           => 'https://api.invalid.example',
    'ALLSTAK_ENVIRONMENT'    => 'test',
    'ALLSTAK_RELEASE'        => 'fixture@' . Options::VERSION,
    // Capture toggles per src/Laravel/config/allstak.php — disable every
    // integration so the fixture doesn't need to bind Kernel / Http / DB /
    // scheduler bindings on a bare container.
    'ALLSTAK_CAPTURE_REQUESTS'        => 'false',
    'ALLSTAK_CAPTURE_EXCEPTIONS'      => 'false',
    'ALLSTAK_CAPTURE_LOGS'            => 'false',
    'ALLSTAK_CAPTURE_DB'              => 'false',
    'ALLSTAK_CAPTURE_HTTP_CLIENT'     => 'false',
    'ALLSTAK_CAPTURE_SCHEDULED_TASKS' => 'false',
    'ALLSTAK_CAPTURE_QUEUE'           => 'false',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
}

echo "== Laravel fixture smoke ==\n";
echo "PHP:     " . PHP_VERSION . "\n";

$illuminateVersion = Application::class;
echo "Illum.:  " . $container::VERSION . "\n";
echo "SDK:     " . Options::VERSION . "\n";

// Register + boot the ServiceProvider.
$provider = new AllStakServiceProvider($container);
$provider->register();
$provider->boot();

$sdk = AllStak::getInstance();
if ($sdk === null) {
    fwrite(STDERR, "FAIL: AllStak::getInstance() returned null after ServiceProvider boot\n");
    exit(1);
}
echo "boot:    OK (SDK initialised)\n";

// Sanity: Sanitizer redacts the canary value across nested arrays.
$masked = Sanitizer::maskMetadata([
    'authorization'  => 'Bearer should_not_leak',
    'stripe_api_key' => 'should_not_leak',
    'order_id'       => 'ORD-42',
    'nested'         => [
        'password' => 'should_not_leak',
        'city'     => 'Riyadh',
    ],
    'csrf' => 'should_not_leak',
]);
$flat = json_encode($masked);
if (str_contains($flat, 'should_not_leak')) {
    fwrite(STDERR, "FAIL: redaction leak: $flat\n");
    exit(1);
}
echo "redact:  OK (no canary on output)\n";

// shutdown() must not throw. CLI runtime has no fastcgi_finish_request so we
// also test the fallback path implicitly.
$threw = false;
try {
    $sdk->shutdown();
} catch (\Throwable $e) {
    $threw = true;
    fwrite(STDERR, "FAIL: shutdown threw: " . $e->getMessage() . "\n");
}
if ($threw) exit(1);
echo "shutdown: OK (no fatal in CLI mode; fastcgi_finish_request fallback safe)\n";

echo "RESULT: PASS\n";
