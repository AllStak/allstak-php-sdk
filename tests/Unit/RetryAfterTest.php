<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Transport\RetryHandler;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function coverage for Retry-After parsing. No real sleeping, no network.
 */
final class RetryAfterTest extends TestCase
{
    public function testIntegerSecondsParsed(): void
    {
        $this->assertSame(2.0, RetryHandler::parseRetryAfter('2'));
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('0'));
        $this->assertSame(120.0, RetryHandler::parseRetryAfter('120'));
    }

    public function testIntegerWithSurroundingWhitespaceParsed(): void
    {
        $this->assertSame(5.0, RetryHandler::parseRetryAfter('  5  '));
    }

    public function testHttpDateInFutureReturnsDelta(): void
    {
        $now = 1_000_000_000; // fixed reference instant
        // 30 seconds after $now, expressed as an HTTP-date (IMF-fixdate, GMT).
        $header = gmdate('D, d M Y H:i:s \G\M\T', $now + 30);
        $this->assertSame(30.0, RetryHandler::parseRetryAfter($header, $now));
    }

    public function testHttpDateInPastReturnsZero(): void
    {
        $now = 1_000_000_000;
        $header = gmdate('D, d M Y H:i:s \G\M\T', $now - 60);
        $this->assertSame(0.0, RetryHandler::parseRetryAfter($header, $now));
    }

    public function testNullReturnsZero(): void
    {
        $this->assertSame(0.0, RetryHandler::parseRetryAfter(null));
    }

    public function testEmptyStringReturnsZero(): void
    {
        $this->assertSame(0.0, RetryHandler::parseRetryAfter(''));
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('   '));
    }

    public function testGarbageReturnsZero(): void
    {
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('soon'));
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('-5'));
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('3.5'));
        $this->assertSame(0.0, RetryHandler::parseRetryAfter('12abc'));
    }

    public function testIntegerSecondsClampedToMax(): void
    {
        $this->assertSame(
            RetryHandler::RETRY_AFTER_MAX_SECONDS,
            RetryHandler::parseRetryAfter('600'),
        );
        $this->assertSame(300.0, RetryHandler::parseRetryAfter('301'));
    }

    public function testHttpDateBeyondMaxClampedToMax(): void
    {
        $now = 1_000_000_000;
        // 10 minutes out — well beyond the 300s ceiling.
        $header = gmdate('D, d M Y H:i:s \G\M\T', $now + 600);
        $this->assertSame(300.0, RetryHandler::parseRetryAfter($header, $now));
    }
}
