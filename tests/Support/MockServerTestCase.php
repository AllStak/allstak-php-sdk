<?php

declare(strict_types=1);

namespace AllStak\Tests\Support;

use AllStak\AllStak;
use PHPUnit\Framework\TestCase;

/**
 * Base test case that boots the {@see mock-server.php} ingest backend on a
 * local port via PHP's built-in web server and points the SDK at it. Tests
 * read {@see recordedRequests()} to assert exactly what the SDK sent on the
 * wire — reusing the repo's existing mock-server transport seam rather than
 * mocking internal classes.
 */
abstract class MockServerTestCase extends TestCase
{
    /** @var resource|null */
    private $serverProcess = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    protected string $host = '';
    protected string $logFile = '';

    protected function setUp(): void
    {
        AllStak::reset();

        $this->logFile = tempnam(sys_get_temp_dir(), 'allstak-mock-') ?: (sys_get_temp_dir() . '/allstak-mock-' . uniqid() . '.json');
        @unlink($this->logFile);

        $port = $this->findFreePort();
        $this->host = "http://127.0.0.1:{$port}";
        $docScript = __DIR__ . '/mock-server.php';

        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", $docScript];

        // Pass only scalar env vars through to the child server; arrays (e.g.
        // $_SERVER['argv']) cannot be stringified for the process environment.
        $env = ['ALLSTAK_MOCK_LOG' => $this->logFile];
        foreach ($_ENV as $k => $v) {
            if (is_scalar($v)) {
                $env[$k] = (string) $v;
            }
        }
        if (($path = getenv('PATH')) !== false && !isset($env['PATH'])) {
            $env['PATH'] = $path;
        }

        $this->serverProcess = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            $env
        );

        if (!is_resource($this->serverProcess)) {
            self::fail('Could not start mock ingest server');
        }

        $this->waitForServer($port);
    }

    protected function tearDown(): void
    {
        AllStak::reset();

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * Initialise the SDK pointing at the mock server with fast-fail timeouts.
     *
     * @param array<string,mixed> $extra Additional config overrides.
     */
    protected function initSdk(array $extra = []): AllStak
    {
        return AllStak::init(array_merge([
            'apiKey' => 'allstak_live_test',
            'host' => $this->host,
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 1000,
            'totalTimeoutMs' => 2000,
        ], $extra));
    }

    /**
     * All requests the mock server has recorded so far.
     *
     * @return list<array{timestamp:string,method:string,path:string,apiKey:?string,payload:mixed}>
     */
    protected function recordedRequests(): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $raw = (string) file_get_contents($this->logFile);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Requests recorded against a given ingest path.
     *
     * @return list<array{timestamp:string,method:string,path:string,apiKey:?string,payload:mixed}>
     */
    protected function requestsForPath(string $path): array
    {
        return array_values(array_filter(
            $this->recordedRequests(),
            static fn(array $r): bool => ($r['path'] ?? '') === $path
        ));
    }

    private function findFreePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return random_int(20000, 40000);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $parts = explode(':', (string) $name);
        return (int) end($parts);
    }

    private function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        self::fail("Mock ingest server did not come up on port {$port}");
    }
}
