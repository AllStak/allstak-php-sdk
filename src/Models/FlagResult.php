<?php

declare(strict_types=1);

namespace AllStak\Models;

final class FlagResult
{
    public function __construct(
        public readonly string $key,
        public readonly bool $enabled,
        public readonly string $value,
        public readonly string $ruleApplied = '',
    ) {}

    public function boolValue(): bool
    {
        return $this->enabled && strtolower($this->value) === 'true';
    }

    public function intValue(): int
    {
        return (int) $this->value;
    }

    public function floatValue(): float
    {
        return (float) $this->value;
    }
}
