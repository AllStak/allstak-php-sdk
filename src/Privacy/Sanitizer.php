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

    private const SENSITIVE_METADATA_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'key',
        'authorization',
        'cookie',
        'csrf',
        'session_id',
        'sessionid',
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
                $masked[$key] = '[MASKED]';
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
