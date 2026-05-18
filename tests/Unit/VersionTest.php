<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Config\Options;
use PHPUnit\Framework\TestCase;

/**
 * Guards against the historical "CHANGELOG says X, code says Y" drift. Runs
 * cheaply in PHPUnit and fails CI if either source-of-truth changes without
 * the other.
 */
final class VersionTest extends TestCase
{
    public function testVersionConstantIsNotEmpty(): void
    {
        $this->assertNotSame('', Options::VERSION, 'Options::VERSION must not be empty');
    }

    public function testVersionMatchesChangelog(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        $this->assertIsString($changelog);
        $this->assertMatchesRegularExpression(
            '/^## \[' . preg_quote(Options::VERSION, '/') . '\]/m',
            $changelog,
            'CHANGELOG.md top entry must match Options::VERSION (' . Options::VERSION . ')',
        );
    }
}
