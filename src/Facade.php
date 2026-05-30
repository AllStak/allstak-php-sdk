<?php

declare(strict_types=1);

namespace AllStak;

use AllStak\Models\UserContext;

/**
 * Static-style facade over {@see AllStak}.
 *
 * Use this when you want to call SDK methods statically from anywhere in
 * your application (Laravel, legacy PHP apps, shared helpers). It proxies
 * to the singleton created by {@see AllStak::init()}. If init() has not
 * been called, every method is a no-op — so the SDK can never crash the
 * host application.
 *
 *   AllStak\AllStak::init([...]);                       // once at bootstrap
 *   AllStak\Facade::captureError($exception);           // anywhere after
 *   AllStak\Facade::captureMessage('hello', 'info');
 *   AllStak\Facade::setUser(new UserContext(id: '42'));
 *   AllStak\Facade::setTag('service', 'checkout');
 *   AllStak\Facade::setContext('region', 'us-east-1');
 *   AllStak\Facade::flush();
 *
 * All method names mirror the instance API on {@see AllStak}. The cross-SDK
 * aliases captureException, flush, and setContext behave the same as the
 * instance methods captureError, shutdown, and setGlobalContext([k=>v]).
 */
final class Facade
{
    private function __construct() {}

    private static function inst(): ?AllStak { return AllStak::getInstance(); }

    public static function setUser(UserContext $user): void
    { if ($i = self::inst()) $i->setUser($user); }

    public static function clearUser(): void
    { if ($i = self::inst()) $i->clearUser(); }

    public static function setUserId(string $userId): void
    { if ($i = self::inst()) $i->setUserId($userId); }

    public static function setTag(string $key, string $value): void
    { if ($i = self::inst()) $i->setTag($key, $value); }

    public static function setTags(array $tags): void
    { if ($i = self::inst()) $i->setTags($tags); }

    public static function setGlobalContext(array $context): void
    { if ($i = self::inst()) $i->setGlobalContext($context); }

    /** Cross-SDK alias. setContext(key, value) → setGlobalContext([key => value]). */
    public static function setContext(string $key, $value): void
    { if ($i = self::inst()) $i->setGlobalContext([$key => $value]); }

    public static function setServiceName(string $name): void
    { if ($i = self::inst()) $i->setServiceName($name); }

    public static function setParentSpanId(string $spanId): void
    { if ($i = self::inst()) $i->setParentSpanId($spanId); }

    public static function setEnvironment(string $environment): void
    { if ($i = self::inst()) $i->setEnvironment($environment); }

    public static function addBreadcrumb(string $type, string $message, string $level = 'info', array $data = []): void
    { if ($i = self::inst()) $i->addBreadcrumb($type, $message, $level, $data); }

    public static function clearBreadcrumbs(): void
    { if ($i = self::inst()) $i->clearBreadcrumbs(); }

    public static function captureError(\Throwable $exception, array $context = []): ?string
    { return ($i = self::inst()) ? $i->captureError($exception, $context) : null; }

    /** Cross-SDK alias for captureError. */
    public static function captureException(\Throwable $exception, array $context = []): ?string
    { return self::captureError($exception, $context); }

    public static function captureMessage(string $message, string $level = 'info', array $metadata = []): ?string
    { return ($i = self::inst()) ? $i->captureMessage($message, $level, $metadata) : null; }

    public static function captureLog(string $level, string $message, array $metadata = [], array $options = []): void
    { if ($i = self::inst()) $i->captureLog($level, $message, $metadata, $options); }

    public static function captureHttpRequest(array $request): void
    { if ($i = self::inst()) $i->captureHttpRequest($request); }

    /** Cross-SDK alias for shutdown — drains all buffers. */
    public static function flush(): void
    { if ($i = self::inst()) $i->shutdown(); }

    public static function shutdown(): void
    { if ($i = self::inst()) $i->shutdown(); }

    public static function getDiagnostics(): Diagnostics
    { return AllStak::getDiagnostics(); }
}
