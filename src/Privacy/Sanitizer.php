<?php

declare(strict_types=1);

namespace AllStak\Privacy;

final class Sanitizer
{
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
     * Strip sensitive headers from an associative array of headers.
     */
    public static function filterHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
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
     */
    public static function maskMetadata(array $metadata): array
    {
        $masked = [];
        foreach ($metadata as $key => $value) {
            $isSecret = false;
            foreach (self::SENSITIVE_METADATA_KEYS as $pattern) {
                if (stripos((string) $key, $pattern) !== false) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $masked[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $masked[$key] = self::maskMetadata($value);
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * Sanitize an error message that might contain SQL with parameter values.
     */
    public static function sanitizeErrorMessage(string $message): string
    {
        // Mask potential connection strings
        $message = preg_replace(
            '#((?:mysql|pgsql|postgres|mongodb|redis)://)[^\s]+#i',
            '$1[FILTERED]',
            $message
        );
        return $message;
    }
}
