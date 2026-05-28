<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Privacy\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    // ─── Header Filtering ────────────────────────────────────────────

    public function testFiltersSensitiveHeaders(): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer secret123',
            'Cookie' => 'session=abc',
            'X-AllStak-Key' => 'allstak_live_xxx',
            'X-API-Key' => 'my-api-key',
            'X-Auth-Token' => 'tok_123',
            'Accept' => 'text/html',
        ];

        $filtered = Sanitizer::filterHeaders($headers);

        $this->assertSame('application/json', $filtered['Content-Type']);
        $this->assertSame('[FILTERED]', $filtered['Authorization']);
        $this->assertSame('[FILTERED]', $filtered['Cookie']);
        $this->assertSame('[FILTERED]', $filtered['X-AllStak-Key']);
        $this->assertSame('[FILTERED]', $filtered['X-API-Key']);
        $this->assertSame('[FILTERED]', $filtered['X-Auth-Token']);
        $this->assertSame('text/html', $filtered['Accept']);
    }

    // ─── Query Param Stripping ───────────────────────────────────────

    public function testStripQueryParams(): void
    {
        $this->assertSame('/api/users', Sanitizer::stripQueryParams('/api/users?token=abc&page=1'));
        $this->assertSame('/api/users', Sanitizer::stripQueryParams('/api/users'));
    }

    public function testFilterQueryParams(): void
    {
        $url = '/search?q=hello&api_key=secret123&page=1';
        $filtered = Sanitizer::filterQueryParams($url);

        $this->assertStringContainsString('q=hello', $filtered);
        $this->assertStringContainsString('page=1', $filtered);
        $this->assertStringContainsString('api_key=%5BFILTERED%5D', $filtered);
        $this->assertStringNotContainsString('secret123', $filtered);
    }

    // ─── Metadata Masking ────────────────────────────────────────────

    public function testMaskMetadata(): void
    {
        $meta = [
            'userId' => 'usr_123',
            'password' => 'hunter2',
            'api_key' => 'sk_live_xxx',
            'secret_value' => 'shhh',
            'token' => 'jwt-token',
            'authorization' => 'Bearer xxx',
            'orderId' => 'ORD-5512',
        ];

        $masked = Sanitizer::maskMetadata($meta);

        $this->assertSame('usr_123', $masked['userId']);
        $this->assertSame('[REDACTED]', $masked['password']);
        $this->assertSame('[REDACTED]', $masked['api_key']);
        $this->assertSame('[REDACTED]', $masked['secret_value']);
        $this->assertSame('[REDACTED]', $masked['token']);
        $this->assertSame('[REDACTED]', $masked['authorization']);
        $this->assertSame('ORD-5512', $masked['orderId']);
    }

    public function testSdkSessionIdProtocolFieldIsNotRedacted(): void
    {
        // The release-health "sessionId" envelope/event field contains the
        // "session" substring but is the SDK's own correlation id — it must
        // survive masking so the backend can correlate the session. Genuine
        // auth session tokens/cookies remain redacted.
        $masked = Sanitizer::maskMetadata([
            'sessionId'  => 'b1f2e3d4-aaaa-4bbb-8ccc-0123456789ab',
            'session'    => 'auth-cookie-value',
            'session_id' => 'php-session-secret',
            'orderId'    => 'ORD-9',
        ]);

        $this->assertSame('b1f2e3d4-aaaa-4bbb-8ccc-0123456789ab', $masked['sessionId']);
        $this->assertSame('[REDACTED]', $masked['session']);
        $this->assertSame('[REDACTED]', $masked['session_id']);
        $this->assertSame('ORD-9', $masked['orderId']);
    }

    // ─── Error Message Sanitization ──────────────────────────────────

    public function testSanitizeConnectionStrings(): void
    {
        $msg = 'Failed to connect: mysql://root:password123@db.host:3306/mydb';
        $sanitized = Sanitizer::sanitizeErrorMessage($msg);
        $this->assertStringContainsString('mysql://', $sanitized);
        $this->assertStringNotContainsString('password123', $sanitized);
        $this->assertStringContainsString('[FILTERED]', $sanitized);
    }

    public function testPlainMessageUnchanged(): void
    {
        $msg = 'Cannot read property of undefined';
        $this->assertSame($msg, Sanitizer::sanitizeErrorMessage($msg));
    }
}
