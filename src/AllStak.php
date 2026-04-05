<?php

declare(strict_types=1);

namespace AllStak;

use AllStak\Buffer\RingBuffer;
use AllStak\Config\Options;
use AllStak\Integrations\ErrorHandler;
use AllStak\Integrations\FeatureFlags;
use AllStak\Integrations\HttpMonitor;
use AllStak\Models\FlagResult;
use AllStak\Models\JobHandle;
use AllStak\Models\UserContext;
use AllStak\Privacy\Sanitizer;
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

    // Request context for error-request correlation
    private ?array $requestContext = null;

    // Feature flags
    private ?FeatureFlags $featureFlags = null;

    // Error handler integration
    private ?ErrorHandler $errorHandler = null;

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

        // Register shutdown function for best-effort drain
        register_shutdown_function([$this, 'shutdown']);

        $this->logger->debug('AllStak SDK initialized', [
            'host' => $options->host,
            'environment' => $options->environment,
            'release' => $options->release,
        ]);
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

    public function setServiceName(string $name): void
    {
        $this->serviceName = $name;
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
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
            $payload = $this->buildErrorPayload($exception, $context);
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

            $merged = array_merge($this->globalContext, $metadata);
            if (!empty($merged)) {
                $payload['metadata'] = Sanitizer::maskMetadata($merged);
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
     */
    public function captureLog(string $level, string $message, array $metadata = []): void
    {
        if ($this->disabled) {
            return;
        }

        try {
            if (!in_array($level, self::VALID_LOG_LEVELS, true)) {
                $this->logger->debug("AllStak SDK: invalid log level '{$level}' — using 'info'");
                $level = 'info';
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

            $merged = array_merge($this->globalContext, $metadata);
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
                'traceId' => $request['traceId'] ?? $this->generateTraceId(),
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

            if (isset($request['userId'])) {
                $item['userId'] = $request['userId'];
            } elseif ($this->user !== null && $this->user->id !== '') {
                $item['userId'] = $this->user->id;
            }

            if (isset($request['errorFingerprint'])) {
                $item['errorFingerprint'] = $request['errorFingerprint'];
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

    // ─── Flushing ────────────────────────────────────────────────────

    /**
     * Flush all buffered events (logs and HTTP requests).
     */
    public function flush(): void
    {
        $this->flushLogs();
        $this->flushHttpRequests();
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

    /**
     * Shutdown handler — best-effort drain with 5-second deadline.
     */
    public function shutdown(): void
    {
        if ($this->disabled) {
            return;
        }

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

        // Session ID
        if (isset($context['sessionId'])) {
            $payload['sessionId'] = $context['sessionId'];
        }

        // Metadata
        $metadata = $context['metadata'] ?? [];
        $merged = array_merge($this->globalContext, $metadata);
        if (!empty($merged)) {
            $payload['metadata'] = Sanitizer::maskMetadata($merged);
        }

        // Request context for error-request correlation
        if ($this->traceId !== '') {
            $payload['traceId'] = $this->traceId;
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

        return $payload;
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
