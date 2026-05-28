<?php

declare(strict_types=1);

namespace AllStak;

use AllStak\Buffer\RingBuffer;
use AllStak\Config\Options;
use AllStak\Integrations\DatabaseMonitor;
use AllStak\Integrations\ErrorHandler;
use AllStak\Integrations\FeatureFlags;
use AllStak\Integrations\HttpMonitor;
use AllStak\Models\FlagResult;
use AllStak\Models\JobHandle;
use AllStak\Models\UserContext;
use AllStak\Privacy\Sanitizer;
use AllStak\Session\SessionTracker;
use AllStak\Transport\HttpClient;
use AllStak\Transport\RetryHandler;

final class AllStak
{
    private static ?self $instance = null;

    private Options $options;
    private SdkLogger $logger;
    private HttpClient $httpClient;
    private RetryHandler $retryHandler;
    private bool $disabled = false;

    // Buffers
    private RingBuffer $logBuffer;
    private RingBuffer $httpRequestBuffer;

    // User context
    private ?UserContext $user = null;

    // Global context (tags/metadata attached to all events)
    private array $globalContext = [];
    private string $serviceName = '';
    private string $traceId = '';
    private string $environment = '';
    private string $userId = '';

    // Request context for error-request correlation
    private ?array $requestContext = null;

    // Distributed tracing: span stack and completed spans buffer
    /** @var string[] Stack of active span IDs (most recent last) */
    private array $spanStack = [];
    /** @var array<int, array{traceId: string, spanId: string, parentSpanId: string, operation: string, description: string, service: string, environment: string, tags: array<string,string>, data: string, startTimeMillis: int}> Active span metadata keyed by array index */
    private array $activeSpans = [];
    /** @var list<array> Completed span payloads awaiting flush */
    private array $completedSpans = [];
    private const SPAN_BATCH_THRESHOLD = 20;

    // Breadcrumbs ring buffer
    private array $breadcrumbs = [];
    private const MAX_BREADCRUMBS = 50;
    private const VALID_BREADCRUMB_TYPES = ['http', 'log', 'ui', 'navigation', 'query', 'default'];
    private const VALID_BREADCRUMB_LEVELS = ['info', 'warn', 'error', 'debug'];

    // Feature flags
    private ?FeatureFlags $featureFlags = null;

    // Database monitor integration
    private ?DatabaseMonitor $databaseMonitor = null;

    // Error handler integration
    private ?ErrorHandler $errorHandler = null;

    // Release-health session tracker (one session per process / app-launch)
    private ?SessionTracker $sessionTracker = null;

    /**
     * PHP-FPM-aware shutdown handler: finish the HTTP response first so the
     * worker is free, then drain the SDK buffers. Other runtimes (CLI,
     * Octane, Swoole) don't define fastcgi_finish_request and skip the call.
     */
    public function shutdown(): void
    {
        if ($this->disabled) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @\fastcgi_finish_request();
        }
        // End the release-health session first so it ships even if a later
        // buffer drain stalls. Best-effort; never throws.
        if ($this->sessionTracker !== null) {
            try {
                $this->sessionTracker->end();
            } catch (\Throwable $e) {
                // swallow — shutdown must never throw
            }
        }
        $this->drainShutdownBuffers();
    }

    private function __construct(Options $options)
    {
        $this->options = $options;
        $this->logger = new SdkLogger($options->debug);
        $this->httpClient = new HttpClient($options, $this->logger);
        $this->retryHandler = new RetryHandler(
            $this->httpClient,
            $this->logger,
            $options->maxRetries,
            fn() => $this->disabled = true,
        );

        $this->logBuffer = new RingBuffer($options->bufferSize, 'logs', $this->logger);
        $this->httpRequestBuffer = new RingBuffer($options->bufferSize, 'http-requests', $this->logger);

        if ($options->bearerToken !== '' && $options->projectId !== '') {
            $this->featureFlags = new FeatureFlags($this->httpClient, $this->logger, $options);
        }

        $this->databaseMonitor = new DatabaseMonitor(
            $this,
            '',
            $options->environment,
        );
        $this->registerRuntimeRelease();
        $this->startSessionTracking();

        // Register shutdown function for best-effort drain
        register_shutdown_function([$this, 'shutdown']);

        $this->logger->debug('AllStak SDK initialized', [
            'host' => $options->host,
            'environment' => $options->environment,
            'release' => $options->release,
        ]);
    }

    private function registerRuntimeRelease(): void
    {
        if (!$this->options->autoRegisterRelease || $this->options->apiKey === '' || $this->options->release === '') {
            return;
        }
        if ($this->isLikelyTestRuntime()) {
            return;
        }

        try {
            $this->httpClient->postIngest('/ingest/v1/releases', [
                'version' => $this->options->release,
                'environment' => $this->options->environment,
                'commitSha' => $this->options->commitSha !== '' ? $this->options->commitSha : null,
                'branch' => $this->options->branch !== '' ? $this->options->branch : null,
                'author' => null,
                'message' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: release registration failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Begin a release-health session for this process / app-launch. Posts
     * {@code /sessions/start} (never sampled) and arms the in-memory status
     * tracker that {@see shutdown()} flushes via {@code /sessions/end}.
     *
     * Skipped when the SDK is disabled, when {@code enableAutoSessionTracking}
     * is false, or under a unit-test runtime so the suite does not emit real
     * session traffic. Fully fail-open: a transport failure never blocks init.
     */
    private function startSessionTracking(): void
    {
        if ($this->disabled || !$this->options->enableAutoSessionTracking) {
            return;
        }
        if ($this->isLikelyTestRuntime()) {
            return;
        }

        try {
            $this->sessionTracker = new SessionTracker($this->options, $this->httpClient, $this->logger);
            $userId = $this->user !== null && $this->user->id !== '' ? $this->user->id : null;
            $this->sessionTracker->start($userId);
        } catch (\Throwable $e) {
            // Session tracking is best-effort and must never break init.
            $this->logger->debug('AllStak SDK: session tracking init failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The current release-health session id, or null when no session is open.
     * Attached to every captured error/event so the backend's error consumer
     * can mark the session errored/crashed server-side.
     */
    public function currentSessionId(): ?string
    {
        return $this->sessionTracker?->currentSessionId();
    }

    /**
     * Mark the active session crashed (UNHANDLED / fatal). Best-effort; no
     * network I/O — the terminal status rides on the {@code /sessions/end}
     * POST emitted at shutdown. Invoked by the global {@see ErrorHandler}.
     */
    public function markSessionCrashed(): void
    {
        $this->sessionTracker?->recordCrash();
    }

    private function isLikelyTestRuntime(): bool
    {
        $argv = $GLOBALS['argv'] ?? [];
        $command = is_array($argv) ? implode(' ', array_map('strval', $argv)) : '';
        return getenv('PHPUNIT_COMPOSER_INSTALL') !== false
            || getenv('APP_ENV') === 'test'
            || getenv('LARAVEL_ENV') === 'testing'
            || str_contains($command, 'phpunit')
            || str_contains($command, 'pest');
    }

    // ─── Initialization ──────────────────────────────────────────────

    public static function init(array $config): self
    {
        if (self::$instance !== null) {
            $logger = new SdkLogger($config['debug'] ?? false);
            $logger->warning('AllStak SDK: init() called more than once — ignoring');
            return self::$instance;
        }

        $options = new Options($config);
        self::$instance = new self($options);
        return self::$instance;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /** Reset for testing purposes only. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Unknown-static-method trap.
     *
     * PHP forbids declaring a static method with the same name as an existing
     * instance method (such as `setUser`, `captureError`), so the static-style
     * README pattern (`AllStak::captureError(...)`) cannot live directly on
     * this class. Use {@see \AllStak\Facade} instead:
     *
     *     use AllStak\AllStak;
     *     use AllStak\Facade as AS;
     *
     *     AllStak::init([...]);
     *     AS::captureError($e);
     *
     * This trap produces a clear, actionable error instead of PHP's cryptic
     * "Non-static method cannot be called statically" when someone tries the
     * legacy static call on the wrong class.
     */
    public static function __callStatic(string $name, array $arguments)
    {
        throw new \BadMethodCallException(sprintf(
            'AllStak::%s() is an instance method. Either call it on the singleton returned by AllStak::init([...]) — e.g. $sdk->%s(...) — or use the static facade: AllStak\\Facade::%s(...).',
            $name, $name, $name
        ));
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    // ─── User Context ────────────────────────────────────────────────

    public function setUser(UserContext $user): void
    {
        $this->user = $user;
    }

    public function clearUser(): void
    {
        $this->user = null;
    }

    // ─── Global Context ──────────────────────────────────────────────

    public function setGlobalContext(array $context): void
    {
        $this->globalContext = $context;
    }

    /**
     * Attach a single key/value tag to all subsequent events. Tags are merged
     * into the global context and shipped as string metadata on errors, logs,
     * and requests.
     */
    public function setTag(string $key, string $value): void
    {
        $this->globalContext[$key] = $value;
    }

    /**
     * Bulk-merge tags into the global context. Existing keys are overwritten.
     * @param array<string,string> $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $k => $v) {
            $this->globalContext[(string) $k] = (string) $v;
        }
    }

    public function setServiceName(string $name): void
    {
        $this->serviceName = $name;
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Set HTTP request context for error-request correlation.
     * Call this at the start of request handling. Errors captured during
     * the request will automatically include this context.
     *
     * @param array{method?: string, path?: string, host?: string, traceId?: string} $context
     */
    public function setRequestContext(array $context): void
    {
        $this->requestContext = $context;
        // Also set traceId from request context if provided
        if (isset($context['traceId']) && $context['traceId'] !== '') {
            $this->traceId = $context['traceId'];
        }
    }

    public function clearRequestContext(): void
    {
        $this->requestContext = null;
    }

    // ─── Distributed Tracing (Spans) ────────────────────────────────

    /**
     * Get the current trace ID. Creates one if none exists.
     */
    public function getTraceId(): string
    {
        if ($this->traceId === '') {
            $this->traceId = bin2hex(random_bytes(16));
        }
        return $this->traceId;
    }

    /**
     * Get the current active span ID (top of stack), or null if no span is active.
     */
    public function getCurrentSpanId(): ?string
    {
        if (empty($this->spanStack)) {
            return null;
        }
        return $this->spanStack[array_key_last($this->spanStack)];
    }

    /**
     * Start a new span. Automatically parented to the current active span.
     *
     * Returns the span ID. Call finishSpan($spanId) when the operation completes.
     *
     * @param string $operation   The operation name (e.g. "db.query", "http.request").
     * @param string $description Human-readable description.
     * @param array<string,string> $tags Key-value tags.
     * @return string The new span ID.
     */
    public function startSpan(string $operation, string $description = '', array $tags = []): string
    {
        $spanId = bin2hex(random_bytes(16));
        $parentSpanId = $this->getCurrentSpanId() ?? '';
        $traceId = $this->getTraceId();

        $this->spanStack[] = $spanId;
        $this->activeSpans[$spanId] = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId,
            'operation' => $operation,
            'description' => $description,
            'service' => $this->serviceName,
            'environment' => $this->environment !== '' ? $this->environment : $this->options->environment,
            'release' => $this->options->release,
            'tags' => $tags,
            'data' => '',
            'startTimeMillis' => (int) (microtime(true) * 1000),
        ];

        return $spanId;
    }

    /**
     * Set a tag on an active span.
     */
    public function setSpanTag(string $spanId, string $key, string $value): void
    {
        if (isset($this->activeSpans[$spanId])) {
            $this->activeSpans[$spanId]['tags'][$key] = $value;
        }
    }

    /**
     * Set arbitrary data on an active span.
     */
    public function setSpanData(string $spanId, string $data): void
    {
        if (isset($this->activeSpans[$spanId])) {
            $this->activeSpans[$spanId]['data'] = $data;
        }
    }

    /**
     * Finish a span and buffer it for flushing.
     *
     * @param string $spanId The span ID returned by startSpan().
     * @param string $status 'ok', 'error', or 'timeout'. Defaults to 'ok'.
     */
    public function finishSpan(string $spanId, string $status = 'ok'): void
    {
        if (!isset($this->activeSpans[$spanId])) {
            return;
        }

        if (!in_array($status, ['ok', 'error', 'timeout'], true)) {
            $status = 'ok';
        }

        $span = $this->activeSpans[$spanId];
        $endTimeMillis = (int) (microtime(true) * 1000);

        $this->completedSpans[] = [
            'traceId' => $span['traceId'],
            'spanId' => $span['spanId'],
            'parentSpanId' => $span['parentSpanId'],
            'operation' => $span['operation'],
            'description' => $span['description'],
            'status' => $status,
            'durationMs' => $endTimeMillis - $span['startTimeMillis'],
            'startTimeMillis' => $span['startTimeMillis'],
            'endTimeMillis' => $endTimeMillis,
            'service' => $span['service'],
            'environment' => $span['environment'],
            'release' => $span['release'] ?? $this->options->release,
            'tags' => !empty($span['tags']) ? (object) $span['tags'] : new \stdClass(),
            'data' => $span['data'],
        ];

        // Remove from active spans and span stack
        unset($this->activeSpans[$spanId]);
        $idx = array_search($spanId, $this->spanStack, true);
        if ($idx !== false) {
            array_splice($this->spanStack, $idx, 1);
        }

        // Auto-flush when threshold is reached
        if (count($this->completedSpans) >= self::SPAN_BATCH_THRESHOLD) {
            $this->flushSpans();
        }
    }

    /**
     * Reset trace context (trace ID, span stack, active spans).
     */
    public function resetTrace(): void
    {
        $this->traceId = '';
        $this->spanStack = [];
        $this->activeSpans = [];
    }

    // ─── Breadcrumbs ─────────────────────────────────────────────────

    /**
     * Add a breadcrumb to the ring buffer. Breadcrumbs are attached to
     * the next captured error event and then cleared.
     *
     * @param string $type    Category: "http", "log", "ui", "navigation", "query", "default".
     * @param string $message Human-readable description.
     * @param string $level   Severity: "info", "warn", "error", "debug". Defaults to "info".
     * @param array  $data    Optional key-value data.
     */
    public function addBreadcrumb(string $type, string $message, string $level = 'info', array $data = []): void
    {
        if ($this->disabled) {
            return;
        }

        $crumb = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'type' => in_array($type, self::VALID_BREADCRUMB_TYPES, true) ? $type : 'default',
            'message' => $message,
            'level' => in_array($level, self::VALID_BREADCRUMB_LEVELS, true) ? $level : 'info',
        ];

        if (!empty($data)) {
            $crumb['data'] = Sanitizer::maskMetadata($data);
        }

        if (count($this->breadcrumbs) >= $this->options->maxBreadcrumbs) {
            array_shift($this->breadcrumbs); // drop oldest
        }
        $this->breadcrumbs[] = $crumb;
    }

    /**
     * Clear all breadcrumbs from the buffer.
     */
    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * Drain breadcrumbs (return and clear). Returns null if empty.
     */
    private function drainBreadcrumbs(): ?array
    {
        if (empty($this->breadcrumbs)) {
            return null;
        }
        $crumbs = $this->breadcrumbs;
        $this->breadcrumbs = [];
        return $crumbs;
    }

    // ─── Error Capture ───────────────────────────────────────────────

    /**
     * Capture an exception and send immediately (errors are urgent).
     */
    public function captureError(\Throwable $exception, array $context = []): ?string
    {
        if ($this->disabled) {
            return null;
        }

        try {
            // Release-health: a captured error escalates the session status.
            // 'fatal' marks the session crashed (unhandled/fatal); any other
            // level is a handled error -> 'errored'. No network I/O here; the
            // terminal status rides on the /sessions/end POST at shutdown.
            if ($this->sessionTracker !== null) {
                if (($context['level'] ?? 'error') === 'fatal') {
                    $this->sessionTracker->recordCrash();
                } else {
                    $this->sessionTracker->recordError();
                }
            }

            $payload = $this->buildErrorPayload($exception, $context);

            // Attach breadcrumbs and clear the buffer
            $breadcrumbs = $this->drainBreadcrumbs();
            if ($breadcrumbs !== null) {
                $payload['breadcrumbs'] = $breadcrumbs;
            }

            $result = $this->retryHandler->sendWithRetry('/ingest/v1/errors', $payload);

            if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
                return $result['body']['data']['id'] ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to capture error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Capture a message as an error-level event.
     */
    public function captureMessage(string $message, string $level = 'info', array $metadata = []): ?string
    {
        if ($this->disabled) {
            return null;
        }

        try {
            // Release-health: error/fatal messages escalate the session status.
            if ($this->sessionTracker !== null) {
                if ($level === 'fatal') {
                    $this->sessionTracker->recordCrash();
                } elseif ($level === 'error') {
                    $this->sessionTracker->recordError();
                }
            }

            $payload = [
                'exceptionClass' => 'Message',
                'message' => Sanitizer::sanitizeErrorMessage($message),
                'level' => $level,
            ];

            if ($this->options->environment !== '') {
                $payload['environment'] = $this->options->environment;
            }
            if ($this->options->release !== '') {
                $payload['release'] = $this->options->release;
            }
            if ($this->user !== null && !$this->user->isEmpty()) {
                $payload['user'] = $this->user->toArray();
            }
            if (($sid = $this->currentSessionId()) !== null) {
                $payload['sessionId'] = $sid;
            }

            // Merge release-tracking tags last so they always reach the wire
            // unless the caller explicitly overrides a key.
            $merged = array_merge($this->options->releaseTags(), $this->globalContext, $metadata);
            if (!empty($merged)) {
                $payload['metadata'] = Sanitizer::maskMetadata($merged);
            }

            // Attach breadcrumbs and clear the buffer
            $breadcrumbs = $this->drainBreadcrumbs();
            if ($breadcrumbs !== null) {
                $payload['breadcrumbs'] = $breadcrumbs;
            }

            $result = $this->retryHandler->sendWithRetry('/ingest/v1/errors', $payload);

            if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
                return $result['body']['data']['id'] ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to capture message', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ─── Log Capture ─────────────────────────────────────────────────

    private const VALID_LOG_LEVELS = ['debug', 'info', 'warn', 'error', 'fatal'];

    /**
     * Capture a log entry. Logs are buffered and flushed periodically.
     *
     * @param string $level     Log severity (debug, info, warn, error, fatal).
     * @param string $message   Log message text.
     * @param array  $metadata  Optional arbitrary key-value metadata.
     * @param array  $options   Optional correlation fields:
     *                          - spanId (string): Span ID for distributed tracing.
     *                          - requestId (string): HTTP request correlation ID.
     *                          - errorId (string): Link to error if log relates to one.
     *                          - environment (string): Override deployment environment.
     *                          - userId (string): Override current user ID.
     */
    public function captureLog(string $level, string $message, array $metadata = [], array $options = []): void
    {
        if ($this->disabled) {
            return;
        }

        try {
            if (!in_array($level, self::VALID_LOG_LEVELS, true)) {
                $this->logger->debug("AllStak SDK: invalid log level '{$level}' — using 'info'");
                $level = 'info';
            }

            if ($this->options->autoBreadcrumbs && in_array($level, ['warn', 'error', 'fatal'], true)) {
                $this->addBreadcrumb('log', $message, $level, $metadata);
            }

            $payload = [
                'level' => $level,
                'message' => $message,
            ];

            if ($this->serviceName !== '') {
                $payload['service'] = $this->serviceName;
            }
            if ($this->traceId !== '') {
                $payload['traceId'] = $this->traceId;
            }

            // Environment: option override > setEnvironment() > Options config
            $env = $options['environment'] ?? ($this->environment !== '' ? $this->environment : $this->options->environment);
            if ($env !== '') {
                $payload['environment'] = $env;
            }

            $rel = $options['release'] ?? $this->options->release;
            if ($rel !== '') {
                $payload['release'] = $rel;
            }

            // User ID: option override > setUserId() > current user context
            $uid = $options['userId'] ?? ($this->userId !== '' ? $this->userId : ($this->user !== null ? $this->user->id : ''));
            if ($uid !== '') {
                $payload['userId'] = $uid;
            }

            if (isset($options['spanId']) && $options['spanId'] !== '') {
                $payload['spanId'] = $options['spanId'];
            } elseif (($currentSpanId = $this->getCurrentSpanId()) !== null) {
                $payload['spanId'] = $currentSpanId;
            }
            if (isset($options['requestId']) && $options['requestId'] !== '') {
                $payload['requestId'] = $options['requestId'];
            }
            if (isset($options['errorId']) && $options['errorId'] !== '') {
                $payload['errorId'] = $options['errorId'];
            }

            $merged = array_merge($this->options->releaseTags(), $this->globalContext, $metadata);
            if (!empty($merged)) {
                $payload['metadata'] = Sanitizer::maskMetadata($merged);
            }

            $this->logBuffer->push($payload);

            // Flush at 80% capacity
            if ($this->logBuffer->isAtFlushThreshold()) {
                $this->flushLogs();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to capture log', ['error' => $e->getMessage()]);
        }
    }

    // ─── HTTP Request Monitoring ─────────────────────────────────────

    /**
     * Record an HTTP request for monitoring. Requests are batched.
     */
    public function captureHttpRequest(array $request): void
    {
        if ($this->disabled) {
            return;
        }

        try {
            // Validate required fields
            $required = ['method', 'host', 'path', 'statusCode', 'durationMs', 'requestSize', 'responseSize'];
            foreach ($required as $field) {
                if (!isset($request[$field])) {
                    $this->logger->debug("AllStak SDK: missing required HTTP field '{$field}' — dropping");
                    return;
                }
            }

            $item = [
                'traceId' => $request['traceId'] ?? $this->getTraceId(),
                'direction' => $request['direction'] ?? 'outbound',
                'method' => strtoupper($request['method']),
                'host' => $request['host'],
                'path' => Sanitizer::stripQueryParams($request['path']),
                'statusCode' => (int) $request['statusCode'],
                'durationMs' => max(0, (int) $request['durationMs']),
                'requestSize' => (int) $request['requestSize'],
                'responseSize' => (int) $request['responseSize'],
                'timestamp' => $request['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z'),
            ];
            if (isset($request['requestId']) && $request['requestId'] !== '') {
                $item['requestId'] = $request['requestId'];
            }
            if (isset($request['spanId']) && $request['spanId'] !== '') {
                $item['spanId'] = $request['spanId'];
            } elseif (($currentSpanId = $this->getCurrentSpanId()) !== null) {
                $item['spanId'] = $currentSpanId;
            }
            if (isset($request['parentSpanId']) && $request['parentSpanId'] !== '') {
                $item['parentSpanId'] = $request['parentSpanId'];
            }

            if (isset($request['userId'])) {
                $item['userId'] = $request['userId'];
            } elseif ($this->user !== null && $this->user->id !== '') {
                $item['userId'] = $this->user->id;
            }

            if (isset($request['errorFingerprint'])) {
                $item['errorFingerprint'] = $request['errorFingerprint'];
            }

            // Release-tracking metadata rides inside the http_requests
            // metadata column so the dashboard can group by SDK / commit /
            // platform without extra ClickHouse columns.
            $releaseTags = $this->options->releaseTags();
            if (!empty($releaseTags)) {
                $item['metadata'] = isset($request['metadata'])
                    ? array_merge($releaseTags, $request['metadata'])
                    : $releaseTags;
            }

            if ($this->options->autoBreadcrumbs) {
                $this->addBreadcrumb(
                    'http',
                    $item['method'] . ' ' . $item['host'] . $item['path'] . ' -> ' . $item['statusCode'],
                    $item['statusCode'] >= 400 ? 'error' : 'info',
                    ['method' => $item['method'], 'path' => $item['path'], 'statusCode' => $item['statusCode'], 'durationMs' => $item['durationMs']]
                );
            }

            $this->httpRequestBuffer->push($item);

            // Flush at 50 items or 80% capacity
            if ($this->httpRequestBuffer->count() >= 50 || $this->httpRequestBuffer->isAtFlushThreshold()) {
                $this->flushHttpRequests();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to capture HTTP request', ['error' => $e->getMessage()]);
        }
    }

    // ─── Cron Job Monitoring ─────────────────────────────────────────

    /**
     * Start tracking a cron job. Returns a handle to pass to finishJob().
     */
    public function startJob(string $slug): JobHandle
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            throw new \InvalidArgumentException(
                "AllStak SDK: invalid slug format '{$slug}' — must match ^[a-z0-9\\-]+$"
            );
        }
        return new JobHandle($slug);
    }

    /**
     * Finish a cron job and send heartbeat immediately.
     */
    public function finishJob(JobHandle $handle, string $status, ?string $message = null): void
    {
        if ($this->disabled) {
            return;
        }

        try {
            $status = strtolower($status);
            if (!in_array($status, ['success', 'failed'], true)) {
                $this->logger->debug("AllStak SDK: invalid job status '{$status}' — using 'failed'");
                $status = 'failed';
            }

            $payload = [
                'slug' => $handle->slug,
                'status' => $status,
                'durationMs' => $handle->elapsedMs(),
            ];

            if ($message !== null) {
                $payload['message'] = $message;
            }

            if ($this->options->environment !== '') {
                $payload['environment'] = $this->options->environment;
            }
            if ($this->options->release !== '') {
                $payload['release'] = $this->options->release;
            }

            $this->retryHandler->sendWithRetry('/ingest/v1/heartbeat', $payload);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to send heartbeat', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Convenience: wrap a callable as a monitored cron job.
     * Re-throws the job exception after sending the heartbeat.
     */
    public function monitorJob(string $slug, callable $job): mixed
    {
        $handle = $this->startJob($slug);
        try {
            $result = $job();
            $this->finishJob($handle, 'success');
            return $result;
        } catch (\Throwable $e) {
            $this->finishJob($handle, 'failed', $e->getMessage());
            throw $e; // rethrow — SDK must not swallow job errors
        }
    }

    // ─── Feature Flags ───────────────────────────────────────────────

    public function getFlag(string $key, ?string $userId = null, array $attributes = []): ?FlagResult
    {
        if ($this->disabled || $this->featureFlags === null) {
            return null;
        }

        try {
            return $this->featureFlags->evaluateFlag($key, $userId, $attributes);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to evaluate flag', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getAllFlags(?string $userId = null, array $attributes = []): array
    {
        if ($this->disabled || $this->featureFlags === null) {
            return [];
        }

        try {
            return $this->featureFlags->evaluateAll($userId, $attributes);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to evaluate flags', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ─── Error Handler Integration ───────────────────────────────────

    /**
     * Register global error and exception handlers.
     * Opt-in per SDK design rule #7.
     */
    public function registerErrorHandler(): void
    {
        $this->errorHandler = new ErrorHandler($this);
        $this->errorHandler->register();
        $this->logger->debug('AllStak SDK: global error handler registered');
    }

    // ─── Database Monitor ─────────────────────────────────────────────

    /**
     * Get the DatabaseMonitor integration instance.
     */
    public function getDatabaseMonitor(): ?DatabaseMonitor
    {
        return $this->databaseMonitor;
    }

    /**
     * Send a raw payload to an arbitrary ingest endpoint.
     * Used by integrations (e.g. DatabaseMonitor) to POST batched data.
     *
     * @return array{statusCode: int, body: array|null, error: string|null}
     */
    public function sendRaw(string $path, array $payload): array
    {
        return $this->retryHandler->sendWithRetry($path, $payload);
    }

    // ─── Flushing ────────────────────────────────────────────────────

    /**
     * Flush all buffered events (logs, HTTP requests, and spans).
     */
    public function flush(): void
    {
        $this->flushLogs();
        $this->flushHttpRequests();
        $this->flushSpans();

        if ($this->databaseMonitor !== null) {
            $this->databaseMonitor->flush();
        }
    }

    private function flushLogs(): void
    {
        if ($this->disabled) {
            return;
        }

        $items = $this->logBuffer->drain();
        foreach ($items as $payload) {
            try {
                $this->retryHandler->sendWithRetry('/ingest/v1/logs', $payload);
            } catch (\Throwable $e) {
                $this->logger->debug('AllStak SDK: failed to flush log', ['error' => $e->getMessage()]);
            }
        }
    }

    private function flushHttpRequests(): void
    {
        if ($this->disabled) {
            return;
        }

        while (!$this->httpRequestBuffer->isEmpty()) {
            $batch = $this->httpRequestBuffer->drainBatch(100);
            if (empty($batch)) {
                break;
            }

            try {
                $payload = ['requests' => $batch];
                $this->retryHandler->sendWithRetry('/ingest/v1/http-requests', $payload);
            } catch (\Throwable $e) {
                $this->logger->debug('AllStak SDK: failed to flush HTTP requests', ['error' => $e->getMessage()]);
            }
        }
    }

    private function flushSpans(): void
    {
        if ($this->disabled || empty($this->completedSpans)) {
            return;
        }

        $spans = $this->completedSpans;
        $this->completedSpans = [];

        try {
            $payload = ['spans' => $spans];
            $this->retryHandler->sendWithRetry('/ingest/v1/spans', $payload);
        } catch (\Throwable $e) {
            $this->logger->debug('AllStak SDK: failed to flush spans', ['error' => $e->getMessage()]);
            // Re-add on failure for next flush attempt
            $this->completedSpans = array_merge($spans, $this->completedSpans);
        }
    }

    /**
     * Best-effort buffer drain with a 5-second deadline. Called by the public
     * {@see shutdown()} entry point, which first releases the HTTP response
     * via fastcgi_finish_request() when running under PHP-FPM so the worker
     * is not held while we POST to ingest.
     */
    private function drainShutdownBuffers(): void
    {
        $deadline = microtime(true) + 5.0;
        $this->logger->debug('AllStak SDK: shutdown — draining buffers');

        try {
            // Flush logs
            $logs = $this->logBuffer->drain();
            foreach ($logs as $payload) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $this->httpClient->postIngest('/ingest/v1/logs', $payload);
                } catch (\Throwable $e) {
                    // best effort
                }
            }

            // Flush HTTP requests
            while (!$this->httpRequestBuffer->isEmpty()) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $batch = $this->httpRequestBuffer->drainBatch(100);
                if (empty($batch)) {
                    break;
                }
                try {
                    $this->httpClient->postIngest('/ingest/v1/http-requests', ['requests' => $batch]);
                } catch (\Throwable $e) {
                    // best effort
                }
            }

            // Flush completed spans
            if (!empty($this->completedSpans) && microtime(true) < $deadline) {
                try {
                    $this->httpClient->postIngest('/ingest/v1/spans', ['spans' => $this->completedSpans]);
                    $this->completedSpans = [];
                } catch (\Throwable $e) {
                    // best effort
                }
            }

            // Flush database queries
            if ($this->databaseMonitor !== null && microtime(true) < $deadline) {
                try {
                    $this->databaseMonitor->flush();
                } catch (\Throwable $e) {
                    // best effort
                }
            }
        } catch (\Throwable $e) {
            // swallow all errors during shutdown
        }

        $this->logger->debug('AllStak SDK: shutdown complete');
    }

    // ─── Internal ────────────────────────────────────────────────────

    private function buildErrorPayload(\Throwable $exception, array $context): array
    {
        $payload = [
            'exceptionClass' => $this->getExceptionClass($exception),
            'message' => Sanitizer::sanitizeErrorMessage($exception->getMessage()),
            'stackTrace' => $this->extractStackTrace($exception),
            'level' => $context['level'] ?? 'error',
        ];

        if ($this->options->environment !== '') {
            $payload['environment'] = $context['environment'] ?? $this->options->environment;
        }
        if ($this->options->release !== '') {
            $payload['release'] = $context['release'] ?? $this->options->release;
        }

        // User context
        if (isset($context['user'])) {
            $payload['user'] = $context['user'];
        } elseif ($this->user !== null && !$this->user->isEmpty()) {
            $payload['user'] = $this->user->toArray();
        }

        // Session ID — explicit context wins; otherwise attach the active
        // release-health session id so the backend can mark it errored/crashed.
        if (isset($context['sessionId'])) {
            $payload['sessionId'] = $context['sessionId'];
        } elseif (($sid = $this->currentSessionId()) !== null) {
            $payload['sessionId'] = $sid;
        }

        // Metadata — merge release-tracking tags first (lowest precedence),
        // then global context, then per-call metadata. Caller wins on collision.
        $metadata = $context['metadata'] ?? [];
        $merged = array_merge($this->options->releaseTags(), $this->globalContext, $metadata);
        if (!empty($merged)) {
            $payload['metadata'] = Sanitizer::maskMetadata($merged);
        }

        // Request context for error-request correlation
        if ($this->traceId !== '') {
            $payload['traceId'] = $this->traceId;
        }
        $currentSpanId = $this->getCurrentSpanId();
        if ($currentSpanId !== null) {
            $payload['spanId'] = $currentSpanId;
        }
        if ($this->requestContext !== null) {
            $reqCtx = [];
            if (isset($this->requestContext['method'])) $reqCtx['method'] = $this->requestContext['method'];
            if (isset($this->requestContext['path'])) $reqCtx['path'] = $this->requestContext['path'];
            if (isset($this->requestContext['host'])) $reqCtx['host'] = $this->requestContext['host'];
            if (!empty($reqCtx)) {
                $payload['requestContext'] = $reqCtx;
            }
        }

        // Phase 3 — v2 ingest contract: top-level identity + structured frames.
        $payload['sdkName']    = $this->options->sdkName    ?? 'allstak-php';
        $payload['sdkVersion'] = $this->options->sdkVersion ?? '1.2.0';
        $payload['platform']   = $this->options->platform   ?? 'php';
        if (!empty($this->options->dist ?? '')) {
            $payload['dist'] = $this->options->dist;
        }
        $structured = $this->extractStructuredFrames($exception);
        if (!empty($structured)) {
            $payload['frames'] = $structured;
        }

        return $payload;
    }

    /**
     * Phase 3 — produce v2 Frame[] from a Throwable using PHP's
     * structured trace ($e->getTrace()). The first entry of getTrace()
     * is the immediate caller of the throw site; we synthesize one more
     * top frame from getFile()/getLine() so the dashboard shows the
     * actual throw location at the top.
     */
    private function extractStructuredFrames(\Throwable $exception): array
    {
        $frames = [];
        $frames[] = [
            'filename' => $exception->getFile(),
            'absPath'  => $exception->getFile(),
            'function' => $this->getExceptionClass($exception),
            'lineno'   => $exception->getLine(),
            'inApp'    => $this->isInAppFile($exception->getFile()),
            'platform' => 'php',
        ];
        foreach ($exception->getTrace() as $f) {
            $file = $f['file']     ?? '';
            $line = $f['line']     ?? 0;
            $func = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $frames[] = [
                'filename' => $file,
                'absPath'  => $file,
                'function' => $func,
                'lineno'   => $line,
                'inApp'    => $this->isInAppFile($file),
                'platform' => 'php',
            ];
            if (count($frames) >= 50) break;
        }
        return $frames;
    }

    private function isInAppFile(string $file): bool
    {
        if ($file === '') return true;
        return strpos($file, '/vendor/') === false;
    }

    private function getExceptionClass(\Throwable $exception): string
    {
        $class = get_class($exception);
        // Use short class name
        $parts = explode('\\', $class);
        return end($parts);
    }

    private function extractStackTrace(\Throwable $exception): array
    {
        $result = [];
        $totalFrames = 0;
        $maxFrames = 100;
        $current = $exception;
        $isFirst = true;

        while ($current !== null && $totalFrames < $maxFrames) {
            // Exception header line
            $className = $this->getExceptionClass($current);
            $message = $current->getMessage();
            if ($isFirst) {
                $header = $className . ($message !== '' ? ": {$message}" : '');
                $isFirst = false;
            } else {
                $header = 'Caused by: ' . $className . ($message !== '' ? ": {$message}" : '');
            }
            $result[] = $header;

            // Throw location as first frame
            if ($current->getFile() !== '') {
                $result[] = sprintf(
                    'at %s(%s:%d)',
                    basename($current->getFile()),
                    $current->getFile(),
                    $current->getLine()
                );
                $totalFrames++;
            }

            // Stack frames
            foreach ($current->getTrace() as $frame) {
                if ($totalFrames >= $maxFrames) break;

                $file = $frame['file'] ?? '<internal>';
                $line = $frame['line'] ?? 0;
                $class = $frame['class'] ?? '';
                $function = $frame['function'] ?? '<unknown>';
                $type = $frame['type'] ?? '';

                $caller = $class !== '' ? "{$class}{$type}{$function}" : $function;
                $result[] = sprintf('at %s(%s:%d)', $caller, $file, $line);
                $totalFrames++;
            }

            $current = $current->getPrevious();
        }

        return $result;
    }

    private function generateTraceId(): string
    {
        return sprintf(
            'trace-%s-%04x',
            bin2hex(random_bytes(8)),
            getmypid() ?: 0
        );
    }
}
