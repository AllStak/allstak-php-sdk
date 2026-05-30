<?php

declare(strict_types=1);

namespace AllStak\Privacy;

final class Sanitizer
{
    private static int $redactionCount = 0;

    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-allstak-key',
        'x-api-key',
        'x-auth-token',
        'x-access-token',
    ];

    private const SENSITIVE_QUERY_PATTERNS = [
        'token',
        'key',
        'secret',
        'password',
        'auth',
        'api_key',
        'csrf',
        'session',
    ];

    /**
     * Canonical AllStak SDK denylist — 25 terms, case-insensitive substring match.
     * Aligned with @allstak/js, @allstak/react-native, allstak-python,
     * allstak-ruby, AllStak (.NET), allstak-go, allstak_flutter, @allstak/next,
     * @allstak/nestjs, @allstak/fastify, sa.allstak (Java) per
     * docs/standards/sdk-platform-standards.md.
     */
    private const SENSITIVE_METADATA_KEYS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'password',
        'passwd',
        'pwd',
        'api_key',
        'apikey',
        'x-api-key',
        'x-allstak-key',
        'x-auth-token',
        'x-access-token',
        'token',
        'bearer',
        'jwt',
        'session',
        'sessionid',
        'session_id',
        'secret',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'csrf',
    ];

    /**
     * SDK protocol field names that are structural (not user-supplied PII) and
     * must never be redacted, even when they collide with a substring on the
     * sensitive denylist. {@code sessionId} is the release-health session
     * envelope id — it contains the {@code session} substring but is the SDK's
     * own correlation id, not an auth session token/cookie. Exact, lower-case
     * key match only; arbitrary user metadata keys remain fully scrubbed.
     */
    private const ALLOWED_PROTOCOL_KEYS = [
        'sessionid', // top-level envelope/event field "sessionId"
    ];

    /**
     * Structural keys whose STRING VALUES (and subtrees) are exempt from
     * value-pattern PII scrubbing. These carry SDK protocol data, stack-frame
     * locations, release/sdk identity, span/operation names, and URLs/paths —
     * none of which are user free-text, and several of which would be corrupted
     * by value scrubbing (e.g. an absPath containing a digit run, a host that
     * is a bare IPv4, a release tag, or the explicit `user` object whose email/
     * ip are intentional identification).
     *
     * URLs/paths have their own dedicated redactors ({@see stripQueryParams},
     * {@see filterQueryParams}); value scrubbing must not double-process them.
     *
     * Exact, lower-case key match. Arbitrary user metadata keys are still fully
     * value-scrubbed.
     */
    private const VALUE_SCRUB_EXEMPT_KEYS = [
        // Explicit identity — setUser data ships as-is (intentional).
        'user',
        // Stack frames / locations.
        'frames', 'stacktrace', 'stack_trace', 'filename', 'function',
        'abspath', 'abs_path', 'file', 'lineno', 'line', 'inapp', 'in_app',
        // Release / sdk identity.
        'release', 'dist', 'version', 'sdk', 'sdkname', 'sdk_name',
        'sdkversion', 'sdk_version', 'platform', 'commit.sha', 'commit.branch',
        // Tracing / correlation ids.
        'traceid', 'trace_id', 'spanid', 'span_id', 'parentspanid',
        'parent_span_id', 'requestid', 'request_id', 'errorid', 'error_id',
        'sessionid', 'session_id', 'errorfingerprint', 'fingerprint',
        // Span / operation names + URLs/paths (own redactors).
        'operation', 'op', 'url', 'path', 'host', 'route',
        // Structural enums / timestamps.
        'level', 'type', 'direction', 'timestamp', 'exceptionclass',
    ];

    /**
     * Replacement token for value-pattern scrubbed PII. Distinct wording is not
     * required by the backend; it matches the key-based {@code [REDACTED]} token
     * for consistency.
     */
    private const VALUE_REDACTION_TOKEN = '[REDACTED]';

    /**
     * Skip value scrubbing for strings longer than this. Value scrubbing runs on
     * the wire path; an unbounded regex scan over a multi-MB blob would be a DoS
     * foot-gun. Oversized strings keep key-based redaction but are not pattern
     * scanned (fail-open toward delivery).
     */
    private const MAX_VALUE_SCAN_LENGTH = 16_384;

    /** Cap recursion depth so a pathological/cyclic-shaped array can't blow the stack. */
    private const MAX_DEPTH = 24;

    /**
     * (A) ALWAYS-ON value patterns (run regardless of sendDefaultPii). Compiled
     * once. Credit cards are handled separately (Luhn-gated) — these are the
     * patterns applied via straight {@code preg_replace}.
     *
     * US SSN REQUIRES the hyphens (NNN-NN-NNNN); bare 9-digit numbers are NOT
     * matched to avoid nuking order ids / numeric identifiers.
     *
     * @var list<string>
     */
    private const ALWAYS_VALUE_PATTERNS = [
        '/\b\d{3}-\d{2}-\d{4}\b/',
    ];

    /**
     * (B) PII value patterns scrubbed UNLESS sendDefaultPii === true. Compiled
     * once.
     *   - Email: standard, conservative address shape.
     *   - IPv4: each octet validated 0-255 to avoid matching version strings
     *     like "10.300.1.2" or "1.2.3.4.5".
     *
     * @var list<string>
     */
    private const PII_VALUE_PATTERNS = [
        // Email
        '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
        // IPv4 with validated octets (0-255 each, exactly four).
        '/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/',
    ];

    /**
     * Credit-card candidate: a 13-19 digit sequence allowing single space/hyphen
     * separators between groups. Confirmed by a Luhn check before redaction so
     * digit runs that fail Luhn (order ids, timestamps) are preserved.
     */
    private const CC_CANDIDATE_PATTERN = '/\b(?:\d[ \-]?){12,18}\d\b/';

    /**
     * Strip sensitive headers from an associative array of headers.
     */
    public static function filterHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
                self::recordRedaction();
                $filtered[$name] = '[FILTERED]';
            } else {
                $filtered[$name] = $value;
            }
        }
        return $filtered;
    }

    /**
     * Strip query parameters from a URL path. Returns path without query string.
     */
    public static function stripQueryParams(string $urlOrPath): string
    {
        $qPos = strpos($urlOrPath, '?');
        if ($qPos !== false) {
            return substr($urlOrPath, 0, $qPos);
        }
        return $urlOrPath;
    }

    /**
     * Filter sensitive query parameters from a URL, replacing values with [FILTERED].
     */
    public static function filterQueryParams(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);
        foreach ($params as $key => &$value) {
            foreach (self::SENSITIVE_QUERY_PATTERNS as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    self::recordRedaction();
                    $value = '[FILTERED]';
                    break;
                }
            }
        }

        $path = $parts['path'] ?? '/';
        return $path . '?' . http_build_query($params);
    }

    /**
     * Mask sensitive fields in a metadata array. Recurses into nested arrays
     * so JSON-style payloads (e.g. ['http' => ['headers' => [...]]]) are
     * fully scrubbed. The input array is never mutated; a fresh array is
     * always returned.
     *
     * Two layers run here:
     *   1. KEY-NAME redaction (always) — values under a sensitive key name
     *      (password/token/cookie/ssn/...) become {@code [REDACTED]}.
     *   2. VALUE-PATTERN PII scrubbing (free-text value layer) — for
     *      string values NOT under a structural/exempt key, embedded credit
     *      cards (Luhn-valid only) and US SSNs are always scrubbed; emails and
     *      IPv4 addresses are scrubbed unless {@code $sendDefaultPii} is true.
     *
     * Fail-open: a value-scrubber error is swallowed per-string so the
     * key-redacted payload still ships (never throws).
     *
     * @param bool $sendDefaultPii When true, the email/IPv4 (B) scrubbers are
     *                             disabled (caller opted into auto PII). The CC
     *                             and SSN (A) scrubbers always run.
     */
    public static function maskMetadata(array $metadata, bool $sendDefaultPii = false): array
    {
        return self::maskMetadataDepth($metadata, $sendDefaultPii, 0, false);
    }

    /**
     * Depth-bounded recursion worker for {@see maskMetadata}.
     *
     * @param bool $valueScrubExempt When true the current subtree sits under a
     *                               structural/exempt key (e.g. `user`, `frames`)
     *                               so value-pattern scrubbing is skipped while
     *                               key-name redaction still applies.
     */
    private static function maskMetadataDepth(array $metadata, bool $sendDefaultPii, int $depth, bool $valueScrubExempt): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $metadata;
        }

        $masked = [];
        foreach ($metadata as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::ALLOWED_PROTOCOL_KEYS, true)) {
                // Known-safe SDK protocol field — keep, but still recurse so any
                // nested user data inside it is scrubbed (without value-scrubbing
                // the protocol id itself).
                $masked[$key] = is_array($value)
                    ? self::maskMetadataDepth($value, $sendDefaultPii, $depth + 1, true)
                    : $value;
                continue;
            }

            $isSecret = false;
            foreach (self::SENSITIVE_METADATA_KEYS as $pattern) {
                if (stripos((string) $key, $pattern) !== false) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                self::recordRedaction();
                $masked[$key] = '[REDACTED]';
                continue;
            }

            // Once exempt, stay exempt for the whole subtree (a structural key's
            // descendants are structural too — e.g. frames[*].filename).
            $childExempt = $valueScrubExempt || in_array($lowerKey, self::VALUE_SCRUB_EXEMPT_KEYS, true);

            if (is_array($value)) {
                $masked[$key] = self::maskMetadataDepth($value, $sendDefaultPii, $depth + 1, $childExempt);
            } elseif (is_string($value) && !$childExempt) {
                $masked[$key] = self::scrubValue($value, $sendDefaultPii);
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * Apply value-pattern PII scrubbing to a single free-text string.
     *
     * Layering:
     *   (A) always: Luhn-valid credit-card runs, hyphenated US SSN.
     *   (B) unless $sendDefaultPii: emails, validated IPv4.
     *
     * Fully fail-open: any scrubber error returns the input unchanged so the
     * wire path never breaks on a pathological value. Oversized strings keep
     * their key-based redaction but are not pattern-scanned.
     */
    public static function scrubValue(string $value, bool $sendDefaultPii = false): string
    {
        if ($value === '' || strlen($value) > self::MAX_VALUE_SCAN_LENGTH) {
            return $value;
        }

        try {
            $original = $value;
            // (A) Credit cards — Luhn-gated, replace only the confirmed run.
            $value = self::scrubCreditCards($value);

            // (A) US SSN (hyphens required) + any other always-on patterns.
            foreach (self::ALWAYS_VALUE_PATTERNS as $pattern) {
                $replaced = preg_replace($pattern, self::VALUE_REDACTION_TOKEN, $value);
                if (is_string($replaced)) {
                    $value = $replaced;
                }
            }

            // (B) Email + IPv4 — opt-out via sendDefaultPii.
            if (!$sendDefaultPii) {
                foreach (self::PII_VALUE_PATTERNS as $pattern) {
                    $replaced = preg_replace($pattern, self::VALUE_REDACTION_TOKEN, $value);
                    if (is_string($replaced)) {
                        $value = $replaced;
                    }
                }
            }

            if ($value !== $original) {
                self::recordRedaction();
            }
            return $value;
        } catch (\Throwable) {
            // Fail-open: never drop/break an event on a scrubber error.
            return $value;
        }
    }

    /**
     * Replace credit-card-shaped digit runs ONLY when they pass the Luhn
     * checksum. Runs that fail Luhn (order ids, timestamps, tracking numbers)
     * are preserved verbatim. Separators (single space/hyphen) are tolerated
     * inside the run and stripped before the Luhn check.
     */
    private static function scrubCreditCards(string $value): string
    {
        $replaced = preg_replace_callback(
            self::CC_CANDIDATE_PATTERN,
            static function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[0]);
                $len = strlen($digits);
                if ($len < 13 || $len > 19) {
                    return $m[0];
                }
                return self::luhnValid($digits) ? self::VALUE_REDACTION_TOKEN : $m[0];
            },
            $value
        );
        return is_string($replaced) ? $replaced : $value;
    }

    /** Standard Luhn (mod-10) checksum over a pure-digit string. */
    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /**
     * Sanitize an error message that might contain SQL with parameter values.
     */
    public static function sanitizeErrorMessage(string $message): string
    {
        // Mask potential connection strings
        $original = $message;
        $replaced = preg_replace(
            '#((?:mysql|pgsql|postgres|mongodb|redis)://)[^\s]+#i',
            '$1[FILTERED]',
            $message
        );
        $message = is_string($replaced) ? $replaced : $original;
        if ($message !== $original) {
            self::recordRedaction();
        }
        return $message;
    }

    public static function redactionCount(): int
    {
        return self::$redactionCount;
    }

    public static function resetRedactionCount(): void
    {
        self::$redactionCount = 0;
    }

    private static function recordRedaction(): void
    {
        self::$redactionCount++;
    }
}
