<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Config\Options;
use PHPUnit\Framework\TestCase;

/**
 * Runtime release auto-detection. Resolution order (highest first):
 *   1. explicit config['release'] wins
 *   2. release env var (ALLSTAK_RELEASE / VERCEL_* / RAILWAY_* / RENDER_*)
 *   3. local git (git describe), cached + guarded
 *   4. SDK VERSION constant fallback
 *
 * The git logic is exercised through detectReleaseFromGit() with an injected
 * fake runner so tests never depend on a real repository.
 */
final class ReleaseAutodetectTest extends TestCase
{
    private const RELEASE_ENV = [
        'ALLSTAK_RELEASE', 'VERCEL_GIT_COMMIT_SHA', 'RAILWAY_GIT_COMMIT_SHA', 'RENDER_GIT_COMMIT',
    ];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::RELEASE_ENV as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        Options::resetGitReleaseCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv("{$k}={$v}");
            }
        }
        Options::resetGitReleaseCache();
    }

    // --- detectReleaseFromGit: parsing & guarding ---------------------------

    public function testDescribeOutputUsedVerbatim(): void
    {
        $runner = static fn (array $args): string => "v1.4.2-3-gabc1234-dirty\n";
        $this->assertSame('v1.4.2-3-gabc1234-dirty', Options::detectReleaseFromGit($runner));
    }

    public function testShortShaWhenDescribeEmptyCleanTree(): void
    {
        $runner = static function (array $args): string {
            return match ($args[0]) {
                'describe' => '',
                'rev-parse' => "abc1234\n",
                'status' => '',
                default => throw new \LogicException('unexpected ' . $args[0]),
            };
        };
        $this->assertSame('abc1234', Options::detectReleaseFromGit($runner));
    }

    public function testShortShaGetsDirtySuffix(): void
    {
        $runner = static function (array $args): string {
            return match ($args[0]) {
                'describe' => throw new \RuntimeException('no tags'),
                'rev-parse' => "abc1234\n",
                'status' => " M src/Config/Options.php\n",
                default => throw new \LogicException('unexpected ' . $args[0]),
            };
        };
        $this->assertSame('abc1234-dirty', Options::detectReleaseFromGit($runner));
    }

    public function testReturnsNullWhenRunnerThrows(): void
    {
        $runner = static function (array $args): string {
            throw new \RuntimeException('git not installed');
        };
        $this->assertNull(Options::detectReleaseFromGit($runner));
    }

    public function testReturnsNullWhenEverythingEmpty(): void
    {
        $runner = static fn (array $args): string => '';
        $this->assertNull(Options::detectReleaseFromGit($runner));
    }

    // --- Options release resolution order -----------------------------------

    public function testExplicitBeatsEverything(): void
    {
        putenv('ALLSTAK_RELEASE=env-release');
        $opts = new Options(['apiKey' => 'k', 'release' => 'explicit-1.0']);
        $this->assertSame('explicit-1.0', $opts->release);
    }

    public function testEnvBeatsGitAndVersion(): void
    {
        putenv('ALLSTAK_RELEASE=env-release');
        $opts = new Options(['apiKey' => 'k']);
        $this->assertSame('env-release', $opts->release);
    }

    public function testVercelEnvDetected(): void
    {
        putenv('VERCEL_GIT_COMMIT_SHA=0123456789abcdef');
        $opts = new Options(['apiKey' => 'k']);
        // Truncated to 12 chars like the other release env vars.
        $this->assertSame('0123456789ab', $opts->release);
    }

    public function testFallsBackToVersionWhenNoEnvNoGit(): void
    {
        // Seed the cache to "no git release" so we exercise the VERSION fallback
        // deterministically regardless of whether the test runs inside a repo.
        Options::resetGitReleaseCache();
        $this->seedGitCacheNull();
        $opts = new Options(['apiKey' => 'k']);
        $this->assertSame(Options::VERSION, $opts->release);
    }

    public function testOptOutDisablesGitAndVersion(): void
    {
        $opts = new Options(['apiKey' => 'k', 'autoDetectRelease' => false]);
        $this->assertSame('', $opts->release);
    }

    public function testOptOutStillHonorsExplicitAndEnv(): void
    {
        putenv('ALLSTAK_RELEASE=env-release');
        $opts = new Options(['apiKey' => 'k', 'autoDetectRelease' => false]);
        $this->assertSame('env-release', $opts->release);
    }

    public function testReleaseNeverEmptyByDefault(): void
    {
        // With auto-detect on and no env, release resolves to git or VERSION.
        $opts = new Options(['apiKey' => 'k']);
        $this->assertNotSame('', $opts->release);
    }

    /**
     * Force the private git cache to "resolved -> null" via reflection so the
     * VERSION fallback test is hermetic (no real git involved).
     */
    private function seedGitCacheNull(): void
    {
        $ref = new \ReflectionProperty(Options::class, 'gitReleaseCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }
}
