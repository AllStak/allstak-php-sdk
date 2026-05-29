<?php

declare(strict_types=1);

namespace AllStak\Session;

use AllStak\Config\Options;
use AllStak\SdkLogger;
use AllStak\Transport\HttpClient;

/**
 * Release-health "one session per process / app-launch".
 *
 * On {@see start()} the SDK posts a {@code /sessions/start} envelope carrying a
 * distinct session id, the resolved release (falling back to the SDK version),
 * and an SDK identifier. On {@see end()} it posts {@code /sessions/end} with the
 * final status + total duration. Errored / crashed transitions are recorded
 * in-memory only — no per-error network I/O — and the backend's error consumer
 * marks the session errored/crashed server-side from the {@code sessionId}
 * carried on every error event.
 *
 * One instance per {@see \AllStak\AllStak} client. In PHP's request/CLI process
 * model a session is one request / one process. Re-entrancy safe: a second
 * {@see start()} is a no-op, and once ended the tracker does not re-arm.
 *
 * Release-health sessions are NEVER sampled — the start POST is always
 * attempted. Every network call is best-effort and fully fail-open: a transport
 * failure must never throw or block init/shutdown.
 *
 * Mirrors {@code dev.allstak.session.SessionTracker} / {@code Session} /
 * {@code SessionStatus} from the AllStak Java SDK.
 */
final class SessionTracker
{
    private const PATH_START = '/ingest/v1/sessions/start';
    private const PATH_END   = '/ingest/v1/sessions/end';

    public const STATUS_OK       = 'ok';
    public const STATUS_ERRORED  = 'errored';
    public const STATUS_CRASHED  = 'crashed';
    public const STATUS_ABNORMAL = 'abnormal';

    private Options $options;
    private HttpClient $httpClient;
    private SdkLogger $logger;

    private ?string $sessionId = null;
    private float $startedAt = 0.0;
    private string $status = self::STATUS_OK;
    private int $errorCount = 0;
    private bool $started = false;
    private bool $ended = false;

    public function __construct(Options $options, HttpClient $httpClient, SdkLogger $logger)
    {
        $this->options = $options;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Idempotent. Generate (or reuse) the session id, record the start
     * timestamp, set the in-memory status to "ok", and POST /sessions/start.
     * The POST is best-effort: any failure is swallowed so SDK init never
     * throws or blocks on a network round-trip.
     *
     * @param string|null $userId Active user id attached to the start envelope.
     */
    public function start(?string $userId = null): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->sessionId ??= $this->generateSessionId();
        $this->startedAt = microtime(true);
        $this->status = self::STATUS_OK;

        $payload = [
            'sessionId'   => $this->sessionId,
            'release'     => $this->resolveRelease(),
            'environment' => $this->options->environment !== '' ? $this->options->environment : null,
            'userId'      => ($userId !== null && $userId !== '') ? $userId : null,
            'sdkName'     => $this->options->sdkName,
            'sdkVersion'  => $this->options->sdkVersion,
            'platform'    => $this->options->platform,
        ];

        try {
            $this->httpClient->postIngest(self::PATH_START, $payload);
            $this->logger->debug('AllStak SDK: session started', ['sessionId' => $this->sessionId]);
        } catch (\Throwable $e) {
            // Network failure must not crash app boot.
            $this->logger->debug('AllStak SDK: session start failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The id of the active session, or {@code null} when no session is open
     * (not started or already ended). Attached to every captured error/event
     * payload so the backend's error consumer can mark the session
     * errored/crashed server-side.
     */
    public function currentSessionId(): ?string
    {
        if (!$this->started || $this->ended) {
            return null;
        }
        return $this->sessionId;
    }

    /** Current in-memory status (ok / errored / crashed / abnormal). */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * Record a HANDLED error against the active session. No network I/O.
     * Bumps status OK -> ERRORED but never downgrades a terminal CRASHED.
     */
    public function recordError(): void
    {
        if (!$this->started || $this->ended) {
            return;
        }
        $this->errorCount++;
        if ($this->status === self::STATUS_OK) {
            $this->status = self::STATUS_ERRORED;
        }
    }

    /**
     * Record an UNHANDLED / fatal crash. No network I/O — the end-of-session
     * POST carries the terminal status. CRASHED overrides ERRORED.
     */
    public function recordCrash(): void
    {
        if (!$this->started || $this->ended) {
            return;
        }
        $this->errorCount++;
        $this->status = self::STATUS_CRASHED;
    }

    /**
     * Terminate the session and POST /sessions/end. Idempotent. If
     * {@code $finalStatus} is null the session's own accumulated status is
     * used. Best-effort with the configured short timeout; never throws or
     * blocks shutdown. The server does not downgrade an already-crashed
     * session.
     */
    public function end(?string $finalStatus = null): void
    {
        if (!$this->started || $this->ended) {
            return;
        }
        $this->ended = true;

        $status = $finalStatus ?? $this->status;
        $payload = [
            'sessionId'  => $this->sessionId,
            'durationMs' => $this->durationMs(),
            'status'     => $status,
        ];

        try {
            $this->httpClient->postIngest(self::PATH_END, $payload);
            $this->logger->debug('AllStak SDK: session ended', [
                'sessionId' => $this->sessionId,
                'status'    => $status,
                'errors'    => $this->errorCount,
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: session end failed', ['error' => $e->getMessage()]);
        }
    }

    /** Elapsed milliseconds since {@see start()}, floored at 0. */
    private function durationMs(): int
    {
        if ($this->startedAt <= 0.0) {
            return 0;
        }
        return (int) max(0, round((microtime(true) - $this->startedAt) * 1000));
    }

    /**
     * Release carried on the session envelope. Falls back to the SDK version
     * when no release was resolved so a release-health session is never
     * dropped for lack of a release (the /sessions/start contract requires a
     * non-null release).
     */
    private function resolveRelease(): string
    {
        $release = $this->options->release;
        if ($release !== '') {
            return $release;
        }
        return $this->options->sdkVersion !== '' ? $this->options->sdkVersion : Options::VERSION;
    }

    private function generateSessionId(): string
    {
        // RFC-4122 v4 style id, consistent with the Java SDK's UUID session id.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
