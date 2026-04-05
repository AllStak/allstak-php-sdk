<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Config\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testValidConfig(): void
    {
        $opts = new Options([
            'apiKey' => 'allstak_live_test123',
            'host' => 'https://allstak.example.com',
            'environment' => 'staging',
            'release' => 'v1.0.0',
            'debug' => true,
        ]);

        $this->assertSame('allstak_live_test123', $opts->apiKey);
        $this->assertSame('https://allstak.example.com', $opts->host);
        $this->assertSame('staging', $opts->environment);
        $this->assertSame('v1.0.0', $opts->release);
        $this->assertTrue($opts->debug);
    }

    public function testDefaults(): void
    {
        $opts = new Options([
            'apiKey' => 'allstak_live_test123',
            'host' => 'https://example.com',
        ]);

        $this->assertSame('', $opts->environment);
        $this->assertSame('', $opts->release);
        $this->assertFalse($opts->debug);
        $this->assertSame(5000, $opts->flushIntervalMs);
        $this->assertSame(500, $opts->bufferSize);
        $this->assertSame(3000, $opts->connectTimeoutMs);
        $this->assertSame(5000, $opts->totalTimeoutMs);
        $this->assertSame(5, $opts->maxRetries);
    }

    public function testMissingApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey is required');
        new Options(['host' => 'https://example.com']);
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Options(['apiKey' => '', 'host' => 'https://example.com']);
    }

    public function testMissingHostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('host is required');
        new Options(['apiKey' => 'test']);
    }

    public function testHostTrailingSlashStripped(): void
    {
        $opts = new Options([
            'apiKey' => 'test',
            'host' => 'https://example.com/',
        ]);
        $this->assertSame('https://example.com', $opts->host);
    }

    public function testProductionRequiresHttps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS is required');
        new Options([
            'apiKey' => 'test',
            'host' => 'http://example.com',
            'environment' => 'production',
        ]);
    }

    public function testNonProductionAllowsHttp(): void
    {
        $opts = new Options([
            'apiKey' => 'test',
            'host' => 'http://localhost:8080',
            'environment' => 'development',
        ]);
        $this->assertSame('http://localhost:8080', $opts->host);
    }
}
