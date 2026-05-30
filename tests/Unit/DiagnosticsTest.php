<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Privacy\Sanitizer;
use AllStak\Tests\Support\MockServerTestCase;

final class DiagnosticsTest extends MockServerTestCase
{
    private string $spoolDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Sanitizer::resetRedactionCount();
        $this->spoolDir = sys_get_temp_dir() . '/allstak-diag-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->spoolDir !== '' && is_dir($this->spoolDir)) {
            foreach (glob($this->spoolDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->spoolDir);
        }
        parent::tearDown();
    }

    public function testStaticDiagnosticsBeforeInitIsDisabledAndPayloadFree(): void
    {
        $diagnostics = AllStak::getDiagnostics();

        $this->assertTrue($diagnostics->disabled);
        $this->assertSame(0, $diagnostics->eventsSent);
        $this->assertStringNotContainsString('password', json_encode($diagnostics, JSON_THROW_ON_ERROR));
    }

    public function testDiagnosticsReportsPrivacySafeCounters(): void
    {
        $sdk = $this->initSdk(['offlineQueuePath' => $this->spoolDir]);

        $sdk->setTraceId('0123456789abcdef0123456789abcdef');
        $sdk->addBreadcrumb('ui', 'save button', 'info', ['password' => 'secret']);
        $spanId = $sdk->startSpan('diagnostics');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId, 'span IDs must be W3C 16-character lowercase hex');
        $sdk->captureMessage('diagnostic event', 'info', ['password' => 'secret']);
        $sdk->finishSpan($spanId);
        $sdk->flush();

        $diagnostics = $sdk->diagnostics();
        $this->assertGreaterThanOrEqual(2, $diagnostics->eventsCaptured);
        $this->assertGreaterThanOrEqual(2, $diagnostics->eventsSent);
        $this->assertSame(0, $diagnostics->eventsFailed);
        $this->assertSame(1, $diagnostics->activeTraceCount);
        $this->assertGreaterThanOrEqual(1, $diagnostics->sanitizerRedactionCount);
        $this->assertSame($diagnostics->eventsSent, AllStak::getDiagnostics()->eventsSent);

        $encoded = json_encode($diagnostics, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('diagnostic event', $encoded);
        $this->assertStringNotContainsString('save button', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
    }

    public function testRetryableErrorCaptureIsPersistedAndCounted(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://127.0.0.1:1',
            'environment' => 'test',
            'maxRetries' => 1,
            'connectTimeoutMs' => 100,
            'totalTimeoutMs' => 200,
            'offlineQueuePath' => $this->spoolDir,
        ]);

        $id = $sdk->captureMessage('offline diagnostic', 'error', ['token' => 'secret']);

        $this->assertNull($id);
        $diagnostics = $sdk->diagnostics();
        $this->assertSame(1, $diagnostics->eventsCaptured);
        $this->assertSame(1, $diagnostics->eventsFailed);
        $this->assertSame(1, $diagnostics->eventsPersisted);
        $this->assertSame(1, $diagnostics->queueSize);

        $files = glob($this->spoolDir . '/*.aspool.json') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('secret', (string) file_get_contents($files[0]));
    }

    public function testW3cParentSpanIsContinuedForFirstBackendSpan(): void
    {
        $sdk = $this->initSdk(['offlineQueuePath' => $this->spoolDir]);

        $sdk->setTraceId('4bf92f3577b34da6a3ce929d0e0e4736');
        $sdk->setParentSpanId('7a3ce929d0e0e473');
        $spanId = $sdk->startSpan('http.server', 'GET /health');
        $sdk->finishSpan($spanId, 'ok');
        $sdk->flush();

        $spanRequests = $this->requestsForPath('/ingest/v1/spans');
        $this->assertNotEmpty($spanRequests);
        $span = $spanRequests[0]['payload']['spans'][0] ?? null;
        $this->assertIsArray($span);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['traceId'] ?? null);
        $this->assertSame('7a3ce929d0e0e473', $span['parentSpanId'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) ($span['spanId'] ?? ''));
    }
}
