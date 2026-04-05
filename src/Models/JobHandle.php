<?php

declare(strict_types=1);

namespace AllStak\Models;

final class JobHandle
{
    public readonly string $slug;
    public readonly float $startTime;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
        $this->startTime = microtime(true);
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startTime) * 1000);
    }
}
