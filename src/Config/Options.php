<?php

declare(strict_types=1);

namespace AllStak\Config;

final class Options
{
    /**
     * Single, static AllStak ingest host. Not customer-configurable on purpose:
     * customers should never have to know which URL their events go to. To point
     * the SDK at a different deployment (e.g. self-hosted), change this constant
     * in one place.
     */
    public const INGEST_HOST = 'https://api.allstak.sa';

    /** SDK version. Surfaced in the User-Agent header sent to the ingest backend, and as `sdk.version` in event metadata. */
    public const VERSION = '1.2.3';
    /** SDK package name — sent on the wire as `sdk.name`. */
    public const SDK_NAME = 'allstak-php';

    public readonly string $apiKey;
    public readonly string $host;
    public readonly string $environment;
    public readonly string $release;
    public readonly int $flushIntervalMs;
    public readonly int $bufferSize;
    public readonly bool $debug;

    // Feature flags (management API)
    public readonly string $bearerToken;
    public readonly string $projectId;
    public readonly int $flagCacheTtlSeconds;

    // Auto-breadcrumbs
    public readonly bool $autoBreadcrumbs;
    public readonly int $maxBreadcrumbs;

    // Transport
    public readonly int $connectTimeoutMs;
    public readonly int $readTimeoutMs;
    public readonly int $totalTimeoutMs;
    public readonly int $maxRetries;

    // Release-tracking metadata (auto-detected from $_ENV / getenv where possible).
    public readonly string $dist;
    public readonly string $commitSha;
    public readonly string $branch;
    public readonly string $platform;
    public readonly string $sdkName;
    public readonly string $sdkVersion;

    public function __construct(array $config)
    {
        if (empty($config['apiKey'])) {
            throw new \InvalidArgumentException('AllStak SDK: apiKey is required and must be non-empty');
        }

        // Host is hardcoded to INGEST_HOST. The optional 'host' config key is
        // accepted for tests/integration injection only and never advertised in
        // public docs.
        $host = rtrim($config['host'] ?? self::INGEST_HOST, '/');
        $env = $config['environment'] ?? '';

        $this->apiKey = $config['apiKey'];
        $this->host = $host;
        $this->environment = $env;
        // Release resolution (highest first):
        //   1. explicit config['release'] — always wins.
        //   2. release env var.
        //   3. local git at init (cached, fully guarded) when autoDetectRelease.
        //   4. SDK VERSION constant when autoDetectRelease.
        $autoDetect = $config['autoDetectRelease'] ?? true;
        $this->release = self::resolveRelease(
            $config['release'] ?? null,
            static function () {
                $v = getenv('ALLSTAK_RELEASE');
                if ($v !== false && $v !== '') return $v;
                foreach (['VERCEL_GIT_COMMIT_SHA', 'RAILWAY_GIT_COMMIT_SHA', 'RENDER_GIT_COMMIT'] as $k) {
                    $g = getenv($k);
                    if ($g !== false && $g !== '') return substr($g, 0, 12);
                }
                return '';
            },
            (bool)$autoDetect,
        );
        $this->flushIntervalMs = $config['flushIntervalMs'] ?? 5000;
        $this->bufferSize = $config['bufferSize'] ?? 500;
        $this->debug = $config['debug'] ?? false;

        $this->autoBreadcrumbs = $config['autoBreadcrumbs'] ?? true;
        $this->maxBreadcrumbs = $config['maxBreadcrumbs'] ?? 50;

        $this->bearerToken = $config['bearerToken'] ?? '';
        $this->projectId = $config['projectId'] ?? '';
        $this->flagCacheTtlSeconds = $config['flagCacheTtlSeconds'] ?? 60;

        $this->connectTimeoutMs = $config['connectTimeoutMs'] ?? 3000;
        $this->readTimeoutMs = $config['readTimeoutMs'] ?? 3000;
        $this->totalTimeoutMs = $config['totalTimeoutMs'] ?? 5000;
        $this->maxRetries = $config['maxRetries'] ?? 5;

        // Release-tracking metadata. Explicit config wins, then env vars.
        $envFirst = static function (array $keys): string {
            foreach ($keys as $k) { $v = getenv($k); if ($v !== false && $v !== '') return $v; }
            return '';
        };
        $this->dist       = (string)($config['dist'] ?? '');
        $this->commitSha  = (string)($config['commitSha'] ?? $envFirst(['ALLSTAK_COMMIT_SHA', 'GIT_COMMIT', 'VERCEL_GIT_COMMIT_SHA', 'RAILWAY_GIT_COMMIT_SHA', 'RENDER_GIT_COMMIT']));
        $this->branch     = (string)($config['branch'] ?? $envFirst(['ALLSTAK_BRANCH', 'GIT_BRANCH', 'VERCEL_GIT_COMMIT_REF', 'RAILWAY_GIT_BRANCH']));
        $this->platform   = (string)($config['platform'] ?? 'php');
        $this->sdkName    = (string)($config['sdkName'] ?? self::SDK_NAME);
        $this->sdkVersion = (string)($config['sdkVersion'] ?? self::VERSION);
    }

    /**
     * Process-wide cache of the git-derived release so we shell out at most
     * once per process. Sentinel `false` = not yet resolved; `null` = resolved
     * to "no git release".
     * @var string|null|false
     */
    private static string|null|false $gitReleaseCache = false;

    /**
     * Resolve the release string following the documented precedence.
     *
     * @param string|null $explicit  Explicit config value (step 1).
     * @param callable():string $envRelease  Returns release from env or '' (step 2).
     * @param bool $autoDetect  When false, steps 3+4 (git + VERSION) are skipped.
     */
    private static function resolveRelease(?string $explicit, callable $envRelease, bool $autoDetect): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }
        $env = $envRelease();
        if ($env !== '') {
            return $env;
        }
        if (!$autoDetect) {
            return '';
        }
        $git = self::cachedGitRelease();
        if ($git !== null && $git !== '') {
            return $git;
        }
        // 4. Final fallback: the SDK version so release is never empty. In a
        //    deployed artifact without a .git this is the EFFECTIVE release —
        //    runtime git detection (step 3) only yields something for
        //    source/dev deployments running inside a checkout.
        return self::VERSION;
    }

    /** Resolve the git release once per process and cache it. */
    private static function cachedGitRelease(): ?string
    {
        if (self::$gitReleaseCache === false) {
            try {
                self::$gitReleaseCache = self::detectReleaseFromGit();
            } catch (\Throwable) {
                self::$gitReleaseCache = null;
            }
        }
        return self::$gitReleaseCache;
    }

    /**
     * Default "git runner": shell out to the real `git` binary from the process
     * working directory with a short timeout. Returns stdout, or throws on any
     * failure (git missing, no repo, non-zero exit) so the caller treats every
     * failure uniformly.
     *
     * @param list<string> $args  git arguments without the leading "git".
     */
    public static function defaultGitRunner(array $args): string
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException('proc_open unavailable');
        }
        $cmd = array_merge(['git'], $args);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('failed to start git');
        }
        // Non-blocking read with a ~2s wall-clock budget so a hung git can't
        // block startup. We must capture the exit code from the FIRST
        // proc_get_status that observes the process exit — once the child is
        // reaped, later status calls (and proc_close) report exitcode -1.
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $exit = null;
        $timedOut = true;
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($pipes[1]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                $timedOut = false;
                break;
            }
            usleep(10_000);
        }
        // Drain anything written between the last read and exit.
        $stdout .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if ($timedOut) {
            @proc_terminate($proc);
            proc_close($proc);
            throw new \RuntimeException('git timed out');
        }
        proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException("git exited {$exit}");
        }
        return $stdout;
    }

    /**
     * Best-effort release string from the local git checkout. Order:
     *   1. `git describe --tags --always --dirty` (preferred).
     *   2. else `git rev-parse --short HEAD`, appending `-dirty` when
     *      `git status --porcelain` is non-empty.
     * Fully guarded: returns null if the runner throws or both strategies yield
     * nothing. Never throws.
     *
     * The git logic is parsed here from an injectable $runner so tests don't
     * need a real repository on disk.
     *
     * @param (callable(list<string>):string)|null $runner
     */
    public static function detectReleaseFromGit(?callable $runner = null): ?string
    {
        $runner ??= [self::class, 'defaultGitRunner'];

        try {
            $described = trim($runner(['describe', '--tags', '--always', '--dirty']));
            if ($described !== '') {
                return $described;
            }
        } catch (\Throwable) {
            // fall through to rev-parse
        }

        try {
            $sha = trim($runner(['rev-parse', '--short', 'HEAD']));
            if ($sha === '') {
                return null;
            }
            try {
                $status = $runner(['status', '--porcelain']);
            } catch (\Throwable) {
                $status = '';
            }
            return trim($status) !== '' ? "{$sha}-dirty" : $sha;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Test seam: clear the process-wide git release cache. */
    public static function resetGitReleaseCache(): void
    {
        self::$gitReleaseCache = false;
    }

    /**
     * Release-tracking tags merged into every event payload's metadata so the
     * dashboard can group / filter by SDK / platform / commit / branch.
     * @return array<string, string>
     */
    public function releaseTags(): array
    {
        $out = [];
        if ($this->sdkName !== '') $out['sdk.name'] = $this->sdkName;
        if ($this->sdkVersion !== '') $out['sdk.version'] = $this->sdkVersion;
        if ($this->platform !== '') $out['platform'] = $this->platform;
        if ($this->dist !== '') $out['dist'] = $this->dist;
        if ($this->commitSha !== '') $out['commit.sha'] = $this->commitSha;
        if ($this->branch !== '') $out['commit.branch'] = $this->branch;
        return $out;
    }
}
