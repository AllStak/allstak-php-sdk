<?php

declare(strict_types=1);

namespace AllStak;

final class Propagation
{
    public static function baggage(string $traceId, ?string $requestId = null, ?string $spanId = null): string
    {
        $parts = ['allstak-trace_id=' . $traceId];
        if ($requestId !== null && $requestId !== '') {
            $parts[] = 'allstak-request_id=' . $requestId;
        }
        if ($spanId !== null && $spanId !== '') {
            $parts[] = 'allstak-span_id=' . $spanId;
        }
        return implode(',', $parts);
    }

    public static function mergeBaggage(?string $existing, string $traceId, ?string $requestId = null, ?string $spanId = null): string
    {
        $parts = [];
        foreach (explode(',', (string) $existing) as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '' && !str_starts_with(strtolower($trimmed), 'allstak-')) {
                $parts[] = $trimmed;
            }
        }
        return implode(',', array_merge($parts, explode(',', self::baggage($traceId, $requestId, $spanId))));
    }
}
