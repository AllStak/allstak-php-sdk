<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\AllStak;

final class ErrorHandler
{
    private AllStak $sdk;
    /** @var callable|null */
    private mixed $previousExceptionHandler = null;
    /** @var callable|null */
    private mixed $previousErrorHandler = null;

    public function __construct(AllStak $sdk)
    {
        $this->sdk = $sdk;
    }

    public function register(): void
    {
        // Exception handler
        $prev = set_exception_handler([$this, 'handleException']);
        if ($prev !== null) {
            $this->previousExceptionHandler = $prev;
        }

        // Error handler — convert PHP errors to ErrorException
        $prevErr = set_error_handler([$this, 'handleError']);
        if ($prevErr !== null) {
            $this->previousErrorHandler = $prevErr;
        }
    }

    public function handleException(\Throwable $exception): void
    {
        try {
            // An uncaught exception reaching the global handler is, by
            // definition, a crash — escalate the release-health session before
            // capturing so the /sessions/end POST reports "crashed".
            $this->sdk->markSessionCrashed();
            $this->sdk->captureError($exception, ['level' => 'fatal']);
        } catch (\Throwable $e) {
            // swallow — SDK must never crash the host app
        }

        // Chain to previous handler
        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);
        }
    }

    public function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // Respect error_reporting() setting
        if (!(error_reporting() & $errno)) {
            return false;
        }

        try {
            $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            $level = match ($errno) {
                E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'fatal',
                E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
                E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'info',
                default => 'error',
            };

            $this->sdk->captureError($exception, ['level' => $level]);
        } catch (\Throwable $e) {
            // swallow
        }

        // Chain to previous handler
        if ($this->previousErrorHandler !== null) {
            return ($this->previousErrorHandler)($errno, $errstr, $errfile, $errline);
        }

        return false; // Let PHP's default handler run too
    }
}
