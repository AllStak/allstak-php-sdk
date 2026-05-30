<?php

declare(strict_types=1);

namespace AllStak\Symfony\EventListener;

use AllStak\AllStak;
use AllStak\Privacy\Sanitizer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Captures unhandled Symfony kernel exceptions into AllStak and attaches
 * per-request context.
 *
 * Subscribes to:
 *   - KernelEvents::REQUEST   — records {method, path, host} so exceptions
 *                               captured during the request carry request
 *                               context (mirrors the Laravel middleware).
 *   - KernelEvents::EXCEPTION — captures the thrown exception with its stack.
 *   - KernelEvents::TERMINATE — drains the SDK buffers and clears request
 *                               context after the response is sent.
 *
 * Supports two extra knobs the core SDK does not model natively:
 *   - sample_rate: fraction (0.0-1.0) of exceptions to forward.
 *   - before_send: callable(Throwable $e, array $hint): ?Throwable — receives a
 *                  sanitized Throwable view; return null to drop the event, or
 *                  a different Throwable to capture. The core SDK sanitizes the
 *                  final payload again before send.
 */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    private ?AllStak $sdk;
    private bool $captureExceptions;
    private bool $captureRequests;
    private float $sampleRate;
    /** @var callable|null */
    private $beforeSend;

    /**
     * @param callable|null $beforeSend callable(Throwable, array): ?Throwable
     */
    public function __construct(
        ?AllStak $sdk,
        bool $captureExceptions = true,
        bool $captureRequests = true,
        float $sampleRate = 1.0,
        ?callable $beforeSend = null,
    ) {
        $this->sdk = $sdk;
        $this->captureExceptions = $captureExceptions;
        $this->captureRequests = $captureRequests;
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->beforeSend = $beforeSend;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run early on the request so context is set before controllers.
            KernelEvents::REQUEST => ['onKernelRequest', 100],
            // Run late on exception (low priority) so framework listeners that
            // convert exceptions to responses still see the raw throwable, and
            // we observe the final unhandled exception.
            KernelEvents::EXCEPTION => ['onKernelException', -64],
            KernelEvents::TERMINATE => ['onKernelTerminate', -128],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->captureRequests) {
            return;
        }
        $sdk = $this->sdk ?? AllStak::getInstance();
        if ($sdk === null || $sdk->isDisabled()) {
            return;
        }
        if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $sdk->setRequestContext([
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'host' => $request->getHost(),
            ]);
        } catch (Throwable $e) {
            // never break the request
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->captureExceptions) {
            return;
        }
        $sdk = $this->sdk ?? AllStak::getInstance();
        if ($sdk === null || $sdk->isDisabled()) {
            return;
        }

        try {
            $throwable = $event->getThrowable();

            // before_send hook: sees a sanitized Throwable view; may rewrite or
            // drop the event. If it returns the sanitized view unchanged, keep
            // capturing the original Throwable so stack/class fidelity is not
            // lost. Any different Throwable is captured and then sanitized by
            // the core SDK before network send.
            if ($this->beforeSend !== null) {
                $sanitizedThrowable = $this->sanitizedThrowableForHook($throwable);
                $result = ($this->beforeSend)($sanitizedThrowable, ['event' => $event]);
                if ($result === null) {
                    return; // dropped by host app
                }
                if ($result instanceof Throwable && $result !== $sanitizedThrowable) {
                    $throwable = $result;
                }
            }

            // Sampling — drop a fraction of events when sample_rate < 1.0.
            if ($this->sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $this->sampleRate) {
                return;
            }

            $sdk->captureError($throwable);
        } catch (Throwable $e) {
            // never break the host app
        }
    }

    private function sanitizedThrowableForHook(Throwable $throwable): Throwable
    {
        $masked = Sanitizer::maskMetadata(['message' => Sanitizer::sanitizeErrorMessage($throwable->getMessage())]);
        $message = is_string($masked['message'] ?? null) ? $masked['message'] : '[FILTERED]';
        try {
            return new \RuntimeException($message, (int) $throwable->getCode(), $throwable->getPrevious());
        } catch (Throwable) {
            return new \RuntimeException('[FILTERED]');
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $sdk = $this->sdk ?? AllStak::getInstance();
        if ($sdk === null || $sdk->isDisabled()) {
            return;
        }
        try {
            $sdk->flush();
            $sdk->clearRequestContext();
            $sdk->resetTrace();
        } catch (Throwable $e) {
            // best effort
        }
    }
}
