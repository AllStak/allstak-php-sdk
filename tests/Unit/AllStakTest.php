<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\AllStak;
use AllStak\Models\JobHandle;
use AllStak\Models\UserContext;
use PHPUnit\Framework\TestCase;

final class AllStakTest extends TestCase
{
    protected function setUp(): void
    {
        AllStak::reset();
    }

    protected function tearDown(): void
    {
        AllStak::reset();
    }

    // ─── Initialization ──────────────────────────────────────────────

    public function testInitReturnsInstance(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        $this->assertInstanceOf(AllStak::class, $sdk);
        $this->assertSame($sdk, AllStak::getInstance());
    }

    public function testDoubleInitReturnsSameInstance(): void
    {
        $first = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        $second = AllStak::init([
            'apiKey' => 'allstak_live_OTHER',
            'host' => 'http://localhost:8888',
        ]);

        $this->assertSame($first, $second);
        // Original config preserved
        $this->assertSame('allstak_live_test', $first->getOptions()->apiKey);
    }

    public function testInitWithInvalidConfigThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AllStak::init(['host' => 'http://localhost']);
    }

    // ─── No-op Before Init ───────────────────────────────────────────

    public function testGetInstanceBeforeInitReturnsNull(): void
    {
        $this->assertNull(AllStak::getInstance());
    }

    // ─── User Context ────────────────────────────────────────────────

    public function testSetAndClearUser(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        $user = new UserContext(id: 'usr_1', email: 'a@b.com', ip: '1.2.3.4');
        $sdk->setUser($user);
        $sdk->clearUser();
        // No assertion needed — just verify it doesn't throw
        $this->assertTrue(true);
    }

    // ─── Job Handle ──────────────────────────────────────────────────

    public function testJobHandleMeasuresElapsed(): void
    {
        $handle = new JobHandle('test-job');
        usleep(50_000); // 50ms
        $elapsed = $handle->elapsedMs();
        $this->assertGreaterThanOrEqual(40, $elapsed);
        $this->assertLessThan(500, $elapsed);
    }

    public function testStartJobValidatesSlug(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        // Valid slug
        $handle = $sdk->startJob('daily-report');
        $this->assertSame('daily-report', $handle->slug);

        // Invalid slug
        $this->expectException(\InvalidArgumentException::class);
        $sdk->startJob('Invalid_Slug!');
    }

    // ─── UserContext Model ───────────────────────────────────────────

    public function testUserContextToArray(): void
    {
        $user = new UserContext(id: 'usr_1', email: 'a@b.com');
        $arr = $user->toArray();
        $this->assertSame(['id' => 'usr_1', 'email' => 'a@b.com'], $arr);
        $this->assertFalse($user->isEmpty());
    }

    public function testEmptyUserContext(): void
    {
        $user = new UserContext();
        $this->assertTrue($user->isEmpty());
        $this->assertSame([], $user->toArray());
    }

    // ─── Graceful Failure ────────────────────────────────────────────

    public function testCaptureErrorGracefulOnUnreachableHost(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:1', // nothing listening
            'maxRetries' => 1,              // fast fail
            'connectTimeoutMs' => 500,
            'totalTimeoutMs' => 1000,
        ]);

        // Should not throw, should return null
        $result = $sdk->captureError(new \RuntimeException('test'));
        $this->assertNull($result);
    }

    public function testCaptureLogDoesNotThrowOnUnreachableHost(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:1',
            'maxRetries' => 1,
            'connectTimeoutMs' => 500,
            'totalTimeoutMs' => 1000,
        ]);

        // Should not throw
        $sdk->captureLog('info', 'test message');
        $sdk->flush();
        $this->assertTrue(true);
    }

    public function testCaptureMessageGracefulOnUnreachableHost(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:1',
            'maxRetries' => 1,
            'connectTimeoutMs' => 500,
            'totalTimeoutMs' => 1000,
        ]);

        $result = $sdk->captureMessage('hello world', 'info');
        $this->assertNull($result);
    }

    // ─── Disabled SDK ────────────────────────────────────────────────

    public function testDisabledSdkIsNoOp(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        // Manually simulate 401 disabling
        // Access private via reflection for testing
        $ref = new \ReflectionClass($sdk);
        $prop = $ref->getProperty('disabled');
        $prop->setAccessible(true);
        $prop->setValue($sdk, true);

        $this->assertTrue($sdk->isDisabled());
        $this->assertNull($sdk->captureError(new \RuntimeException('nope')));
        $this->assertNull($sdk->captureMessage('nope'));

        // captureLog doesn't return, just verify no exception
        $sdk->captureLog('error', 'nope');
        $sdk->flush();
        $this->assertTrue(true);
    }

    // ─── Log Level Validation ────────────────────────────────────────

    public function testInvalidLogLevelDefaultsToInfo(): void
    {
        $sdk = AllStak::init([
            'apiKey' => 'allstak_live_test',
            'host' => 'http://localhost:9999',
        ]);

        // Should not throw — invalid level is silently corrected
        $sdk->captureLog('banana', 'test message');
        $this->assertTrue(true);
    }
}
