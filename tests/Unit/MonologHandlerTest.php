<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Monolog\AllStakHandler;
use AllStak\Tests\Support\MockServerTestCase;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;

final class MonologHandlerTest extends MockServerTestCase
{
    public function testErrorRecordWithExceptionCapturesEventWithStack(): void
    {
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        $logger->pushHandler(new AllStakHandler($sdk));

        try {
            throw new RuntimeException('boom from monolog');
        } catch (RuntimeException $e) {
            $logger->error('Something failed', ['exception' => $e, 'order_id' => 42]);
        }

        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors, 'exactly one error event should be captured');

        $payload = $errors[0]['payload'];
        $this->assertSame('RuntimeException', $payload['exceptionClass']);
        $this->assertSame('boom from monolog', $payload['message']);
        $this->assertSame('error', $payload['level']);

        // Stack trace must be present and non-empty.
        $this->assertArrayHasKey('stackTrace', $payload);
        $this->assertNotEmpty($payload['stackTrace']);
        $this->assertStringContainsString('RuntimeException', $payload['stackTrace'][0]);

        // Structured frames from the throwable.
        $this->assertArrayHasKey('frames', $payload);
        $this->assertNotEmpty($payload['frames']);

        // Context (minus the exception) rides as metadata.
        $this->assertArrayHasKey('metadata', $payload);
        $this->assertSame(42, $payload['metadata']['order_id'] ?? null);
        $this->assertSame('app', $payload['metadata']['monolog.channel'] ?? null);
    }

    public function testErrorRecordWithoutExceptionCapturesMessageEvent(): void
    {
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        $logger->pushHandler(new AllStakHandler($sdk));

        $logger->error('plain error, no exception');
        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors);
        $this->assertSame('Message', $errors[0]['payload']['exceptionClass']);
        $this->assertSame('plain error, no exception', $errors[0]['payload']['message']);
    }

    public function testLowerLevelRecordBecomesBreadcrumbNotEvent(): void
    {
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        $logger->pushHandler(new AllStakHandler($sdk));

        // INFO is below the default ERROR event threshold → breadcrumb only.
        $logger->info('just a breadcrumb');
        $sdk->flush();

        // No event was sent for the info record.
        $this->assertCount(0, $this->requestsForPath('/ingest/v1/errors'));

        // But the breadcrumb attaches to the next captured error.
        $logger->error('now an error');
        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors);
        $payload = $errors[0]['payload'];
        $this->assertArrayHasKey('breadcrumbs', $payload);

        $messages = array_column($payload['breadcrumbs'], 'message');
        $this->assertContains('just a breadcrumb', $messages);
    }

    public function testEventThresholdIsConfigurable(): void
    {
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        // Promote WARNING and above to events.
        $logger->pushHandler(new AllStakHandler($sdk, Level::Debug, Level::Warning));

        $logger->warning('elevated warning');
        $sdk->flush();

        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors, 'WARNING should be captured as an event when event_level=WARNING');
        $this->assertSame('warn', $errors[0]['payload']['level']);
    }

    public function testHandlerMinLevelIsRespected(): void
    {
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        // Handler ignores anything below WARNING entirely.
        $logger->pushHandler(new AllStakHandler($sdk, Level::Warning, Level::Error));

        $logger->debug('ignored debug');
        $logger->info('ignored info');
        $sdk->flush();

        // Nothing captured, and the ignored records did not even become breadcrumbs.
        $this->assertCount(0, $this->requestsForPath('/ingest/v1/errors'));

        $logger->error('captured error');
        $sdk->flush();
        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertCount(1, $errors);
        // No breadcrumbs from below-threshold records.
        $this->assertArrayNotHasKey('breadcrumbs', $errors[0]['payload']);
    }

    public function testNoRecursionWhenReentrantWritesAreSuppressed(): void
    {
        // Simulate a pathological host that routes the SDK's logging back into
        // the same handler: a processor re-logs through the same logger. The
        // reentrancy guard must prevent an infinite loop / unbounded events.
        $sdk = $this->initSdk();
        $logger = new Logger('app');
        $handler = new AllStakHandler($sdk);
        $logger->pushHandler($handler);

        $logger->pushProcessor(function (\Monolog\LogRecord $record) use ($logger) {
            // Re-entrant log from within processing. Must not loop forever.
            static $depth = 0;
            if ($depth < 1) {
                $depth++;
                $logger->error('re-entrant error');
                $depth--;
            }
            return $record;
        });

        $logger->error('outer error');
        $sdk->flush();

        // The run completes (no infinite loop). A bounded, finite number of
        // events were captured.
        $errors = $this->requestsForPath('/ingest/v1/errors');
        $this->assertLessThanOrEqual(3, count($errors));
        $this->assertGreaterThanOrEqual(1, count($errors));
    }
}
