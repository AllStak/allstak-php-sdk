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

    // ─── Value-Pattern PII Scrubbing ─────────────────────────────────

    public function testCreditCardRedactedOnlyWhenLuhnValid(): void
    {
        // 4111 1111 1111 1111 is the canonical Luhn-valid Visa test number.
        $masked = Sanitizer::maskMetadata([
            'note' => 'card on file 4111 1111 1111 1111 charged',
        ]);
        $this->assertStringContainsString('[REDACTED]', $masked['note']);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $masked['note']);
        // Surrounding free text is preserved (conservative scrubbing).
        $this->assertStringContainsString('card on file', $masked['note']);
        $this->assertStringContainsString('charged', $masked['note']);
    }

    public function testLuhnInvalidDigitRunIsPreserved(): void
    {
        // A 16-digit run that FAILS Luhn (e.g. an order/tracking id) must NOT be
        // nuked — over-redaction that corrupts legitimate data is a failure mode.
        $orderId = '1234567890123456'; // fails Luhn
        $this->assertFalse((function () use ($orderId) {
            // sanity: confirm the fixture really is Luhn-invalid
            $sum = 0; $alt = false;
            for ($i = strlen($orderId) - 1; $i >= 0; $i--) {
                $d = (int) $orderId[$i];
                if ($alt) { $d *= 2; if ($d > 9) $d -= 9; }
                $sum += $d; $alt = !$alt;
            }
            return $sum % 10 === 0;
        })());

        $masked = Sanitizer::maskMetadata(['orderRef' => "order {$orderId} shipped"]);
        $this->assertStringContainsString($orderId, $masked['orderRef']);
        $this->assertStringNotContainsString('[REDACTED]', $masked['orderRef']);
    }

    public function testSsnRedactedWithHyphens(): void
    {
        $masked = Sanitizer::maskMetadata(['note' => 'SSN 123-45-6789 on file']);
        $this->assertStringContainsString('[REDACTED]', $masked['note']);
        $this->assertStringNotContainsString('123-45-6789', $masked['note']);
    }

    public function testBareNineDigitNumberIsNotTreatedAsSsn(): void
    {
        // Without hyphens we must NOT match a bare 9-digit number (could be an id).
        $masked = Sanitizer::maskMetadata(['ref' => 'id 123456789 here']);
        $this->assertStringContainsString('123456789', $masked['ref']);
        $this->assertStringNotContainsString('[REDACTED]', $masked['ref']);
    }

    public function testEmailAndIpv4RedactedWhenSendDefaultPiiFalse(): void
    {
        // Default (sendDefaultPii = false) is privacy-safe: free-text email + IP
        // are scrubbed.
        $masked = Sanitizer::maskMetadata([
            'note' => 'contact jane.doe@example.com from 192.168.1.10',
        ]);
        $this->assertStringNotContainsString('jane.doe@example.com', $masked['note']);
        $this->assertStringNotContainsString('192.168.1.10', $masked['note']);
        $this->assertStringContainsString('[REDACTED]', $masked['note']);
        $this->assertStringContainsString('contact', $masked['note']);
        $this->assertStringContainsString('from', $masked['note']);
    }

    public function testEmailAndIpv4PreservedWhenSendDefaultPiiTrue(): void
    {
        // Opt-in: the (B) scrubbers are disabled.
        $masked = Sanitizer::maskMetadata(
            ['note' => 'contact jane.doe@example.com from 192.168.1.10'],
            true
        );
        $this->assertStringContainsString('jane.doe@example.com', $masked['note']);
        $this->assertStringContainsString('192.168.1.10', $masked['note']);
    }

    public function testCreditCardAndSsnAlwaysScrubbedEvenWithSendDefaultPiiTrue(): void
    {
        // (A) high-risk financial/identity data is ALWAYS scrubbed.
        $masked = Sanitizer::maskMetadata(
            ['note' => 'card 4111 1111 1111 1111 ssn 123-45-6789'],
            true
        );
        $this->assertStringNotContainsString('4111 1111 1111 1111', $masked['note']);
        $this->assertStringNotContainsString('123-45-6789', $masked['note']);
    }

    public function testInvalidIpv4OctetsNotRedacted(): void
    {
        // 999.1.1.1 has an out-of-range octet — the validated regex must NOT
        // match it (avoids corrupting version-like strings).
        $masked = Sanitizer::maskMetadata(['v' => 'build 999.1.1.1 deployed']);
        $this->assertStringContainsString('999.1.1.1', $masked['v']);
        $this->assertStringNotContainsString('[REDACTED]', $masked['v']);
    }

    public function testExplicitUserObjectIsNotValueScrubbed(): void
    {
        // The explicit `user` object (setUser) ships as-is even with the default
        // sendDefaultPii=false — intentional identification.
        $payload = [
            'message' => 'boom',
            'user' => [
                'id' => 'usr_1',
                'email' => 'jane.doe@example.com',
                'ip' => '192.168.1.10',
            ],
        ];
        $masked = Sanitizer::maskMetadata($payload); // default false
        $this->assertSame('jane.doe@example.com', $masked['user']['email']);
        $this->assertSame('192.168.1.10', $masked['user']['ip']);
        $this->assertSame('usr_1', $masked['user']['id']);
    }

    public function testStackFramePathsNotCorrupted(): void
    {
        // Frame filename/function/absPath must survive intact — they are not
        // user free-text and value scrubbing would corrupt them.
        $payload = [
            'frames' => [[
                'filename' => '/var/www/app/v1.2.3/Service.php',
                'absPath'  => '/var/www/app/v1.2.3/Service.php',
                'function' => 'App\\Mailer::send',
                'lineno'   => 42,
            ]],
            'release' => '1.2.3',
            'host'    => '10.0.0.1', // host has its own handling; not scrubbed
        ];
        $masked = Sanitizer::maskMetadata($payload); // default false
        $this->assertSame('/var/www/app/v1.2.3/Service.php', $masked['frames'][0]['filename']);
        $this->assertSame('/var/www/app/v1.2.3/Service.php', $masked['frames'][0]['absPath']);
        $this->assertSame('App\\Mailer::send', $masked['frames'][0]['function']);
        $this->assertSame('1.2.3', $masked['release']);
        $this->assertSame('10.0.0.1', $masked['host']);
    }

    public function testKeyBasedRedactionStillWorksAlongsideValueScrubbing(): void
    {
        // Regression: key-name redaction must remain intact after adding value
        // scrubbing. A sensitive key wins outright (value not even scanned).
        $masked = Sanitizer::maskMetadata([
            'password'  => 'hunter2',
            'api_key'   => 'sk_live_xxx',
            'freeText'  => 'reach me at ops@example.com',
        ]);
        $this->assertSame('[REDACTED]', $masked['password']);
        $this->assertSame('[REDACTED]', $masked['api_key']);
        $this->assertStringNotContainsString('ops@example.com', $masked['freeText']);
    }

    public function testFailOpenOnPathologicalInput(): void
    {
        // A very large string must not throw and must not be dropped — it keeps
        // key-based redaction but is not pattern-scanned (bounded scan length).
        $big = str_repeat('a', 50_000) . ' 4111 1111 1111 1111';
        $masked = Sanitizer::maskMetadata(['blob' => $big]);
        // Returned unchanged (fail-open / oversize skip): no exception, value intact.
        $this->assertSame($big, $masked['blob']);
    }

    public function testNonStringScalarsPassThrough(): void
    {
        $masked = Sanitizer::maskMetadata([
            'count' => 42,
            'ratio' => 1.5,
            'ok'    => true,
            'nil'   => null,
        ]);
        $this->assertSame(42, $masked['count']);
        $this->assertSame(1.5, $masked['ratio']);
        $this->assertTrue($masked['ok']);
        $this->assertNull($masked['nil']);
    }

    public function testScrubValueIsExposedAndComposes(): void
    {
        // Direct helper: CC + SSN always; email/IP gated.
        $in = 'mail a@b.com ip 10.0.0.1 cc 4111 1111 1111 1111 ssn 123-45-6789';
        $scrubbedDefault = Sanitizer::scrubValue($in, false);
        $this->assertStringNotContainsString('a@b.com', $scrubbedDefault);
        $this->assertStringNotContainsString('10.0.0.1', $scrubbedDefault);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $scrubbedDefault);
        $this->assertStringNotContainsString('123-45-6789', $scrubbedDefault);

        $scrubbedPii = Sanitizer::scrubValue($in, true);
        $this->assertStringContainsString('a@b.com', $scrubbedPii);
        $this->assertStringContainsString('10.0.0.1', $scrubbedPii);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $scrubbedPii);
        $this->assertStringNotContainsString('123-45-6789', $scrubbedPii);
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
