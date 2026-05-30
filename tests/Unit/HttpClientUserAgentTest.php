<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Config\Options;
use AllStak\SdkLogger;
use AllStak\Session\SessionTracker;
use AllStak\Tests\Support\MockServerTestCase;
use AllStak\Transport\HttpClient;

/**
 * Wire-contract guard: every request the transport makes MUST carry the
 * mandated User-Agent header `allstak-php/<version>`. The HttpClient sends real
 * curl requests, so this is asserted end-to-end against the mock ingest server
 * (which records the inbound User-Agent header) rather than mocked internally.
 *
 * The {@see SessionTracker} is used purely as a thin driver that exercises the
 * shared {@see HttpClient} the SDK wires in production; the header under test is
 * set by the transport regardless of which endpoint is called.
 */
final class HttpClientUserAgentTest extends MockServerTestCase
{
    private function makeTransport(): array
    {
        $options = new Options([
            'apiKey'      => 'allstak_live_test',
            'host'        => $this->host,
            'environment' => 'test',
            'release'     => '9.9.9',
            'maxRetries'  => 1,
            'connectTimeoutMs' => 1000,
            'totalTimeoutMs'   => 2000,
        ]);

        $client = new HttpClient($options, new SdkLogger(false));

        return [$options, $client];
    }

    public function testIngestPostSendsMandatedUserAgent(): void
    {
        [$options, $client] = $this->makeTransport();
        $tracker = new SessionTracker($options, $client, new SdkLogger(false));
        $tracker->start('usr_ua');

        $starts = $this->requestsForPath('/ingest/v1/sessions/start');
        $this->assertCount(1, $starts, 'exactly one ingest request should reach the server');

        $expected = Options::SDK_NAME . '/' . Options::VERSION;
        $this->assertSame('allstak-php/' . Options::VERSION, $expected, 'User-Agent must follow allstak-<sdk>/<version>');
        $this->assertSame($expected, $starts[0]['userAgent'], 'ingest POST must send User-Agent: ' . $expected);
    }

    public function testTinyIngestPayloadIsNotCompressedAndCounted(): void
    {
        [, $client] = $this->makeTransport();

        $result = $client->postIngest('/ingest/v1/logs', ['message' => 'hi']);

        $this->assertSame(202, $result['statusCode']);
        $logs = $this->requestsForPath('/ingest/v1/logs');
        $this->assertSame('hi', $logs[0]['payload']['message']);
        $this->assertNull($logs[0]['contentEncoding']);
        $diagnostics = $client->diagnostics();
        $this->assertSame(1, $diagnostics['uncompressedPayloads']);
        $this->assertSame(0, $diagnostics['compressedPayloads']);
        $this->assertSame(0, $diagnostics['compressionBytesSaved']);
    }

    public function testLargeIngestPayloadIsGzippedAndCounted(): void
    {
        [, $client] = $this->makeTransport();
        $message = str_repeat('x', 8_000);

        $result = $client->postIngest('/ingest/v1/errors', ['message' => $message]);

        $this->assertSame(202, $result['statusCode']);
        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertSame('gzip', $errors[0]['contentEncoding']);
        $this->assertSame($message, $errors[0]['payload']['message']);
        $diagnostics = $client->diagnostics();
        $this->assertSame(1, $diagnostics['compressedPayloads']);
        $this->assertSame(0, $diagnostics['uncompressedPayloads']);
        $this->assertGreaterThan(0, $diagnostics['compressionBytesSaved']);
    }

    public function testUserAgentFormatMatchesContract(): void
    {
        // allstak-<sdkname>/<version> with no extra whitespace or trailing data.
        [$options, $client] = $this->makeTransport();
        $tracker = new SessionTracker($options, $client, new SdkLogger(false));
        $tracker->start();
        $tracker->end();

        foreach (['/ingest/v1/sessions/start', '/ingest/v1/sessions/end'] as $path) {
            $entry = $this->requestsForPath($path)[0] ?? null;
            $this->assertNotNull($entry, "expected a recorded request for {$path}");
            $this->assertMatchesRegularExpression(
                '#^allstak-php/\d+\.\d+\.\d+#',
                (string) $entry['userAgent'],
                "User-Agent on {$path} must match allstak-php/<semver>"
            );
        }
    }

    /**
     * A framework SDK that wraps this core (e.g. the Symfony or Laravel bundle)
     * passes its own sdkName/sdkVersion into Options. The wire User-Agent MUST
     * then be attributed under that framework SDK — `allstak-symfony/0.1.0` —
     * not the core's own `allstak-php/<version>`, matching the sdkName /
     * sdkVersion already carried in the JSON body.
     */
    public function testUserAgentReflectsWrappingFrameworkSdkIdentity(): void
    {
        $options = new Options([
            'apiKey'      => 'allstak_live_test',
            'host'        => $this->host,
            'environment' => 'test',
            'maxRetries'  => 1,
            'connectTimeoutMs' => 1000,
            'totalTimeoutMs'   => 2000,
            'sdkName'     => 'allstak-symfony',
            'sdkVersion'  => '0.1.0',
        ]);
        $client = new HttpClient($options, new SdkLogger(false));
        $tracker = new SessionTracker($options, $client, new SdkLogger(false));
        $tracker->start('usr_fw');

        $starts = $this->requestsForPath('/ingest/v1/sessions/start');
        $this->assertCount(1, $starts, 'exactly one ingest request should reach the server');
        $this->assertSame(
            'allstak-symfony/0.1.0',
            $starts[0]['userAgent'],
            'a wrapping framework SDK must own its wire User-Agent'
        );
    }
}
