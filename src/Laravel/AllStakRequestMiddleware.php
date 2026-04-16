<?php

declare(strict_types=1);

namespace AllStak\Laravel;

use AllStak\AllStak;
use AllStak\Models\UserContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Captures every inbound HTTP request handled by Laravel into AllStak's
 * /ingest/v1/http-requests channel and sets the per-request context that
 * later error captures use to populate {method, path, host, traceId}.
 */
class AllStakRequestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $sdk = AllStak::getInstance();
        $startMs = microtime(true);
        $traceId = bin2hex(random_bytes(16));
        $spanId = null;
        $path = $request->path() === '/' ? '/' : '/' . ltrim($request->path(), '/');

        if ($sdk !== null) {
            try {
                $sdk->setRequestContext([
                    'method' => $request->method(),
                    'path' => $path,
                    'host' => $request->getHost(),
                    'traceId' => $traceId,
                ]);
                // Open a root span for the request so DB queries / log calls / outbound
                // HTTP made during the request are correlated under one trace.
                $spanId = $sdk->startSpan(
                    $request->method() . ' ' . $path,
                    'HTTP ' . $request->method() . ' ' . $path,
                    [
                        'http.method' => $request->method(),
                        'http.url' => $path,
                        'http.host' => $request->getHost(),
                    ]
                );
            } catch (Throwable $e) {
                // never break the request
            }
        }

        /** @var Response $response */
        $response = $next($request);

        // Auto-attach the authenticated user (if any) AFTER the request runs,
        // so $sdk->captureHttpRequest() and any subsequent capture sees a
        // populated UserContext. Errors thrown during the request go through
        // the AllStakServiceProvider's reportable() callback which also
        // re-attaches the user at error-report time.
        if ($sdk !== null && $request->user() !== null) {
            try {
                $u = $request->user();
                $sdk->setUser(new UserContext(
                    (string) ($u->getAuthIdentifier() ?? ''),
                    (string) ($u->email ?? ''),
                    (string) ($request->ip() ?? '')
                ));
            } catch (Throwable $e) {
                // never break the request
            }
        }

        if ($sdk !== null) {
            try {
                $durationMs = (int) round((microtime(true) - $startMs) * 1000);
                $statusCode = $response->getStatusCode();
                $sdk->captureHttpRequest([
                    'traceId' => $traceId,
                    'direction' => 'inbound',
                    'method' => $request->method(),
                    'host' => $request->getHost(),
                    'path' => $path,
                    'statusCode' => $statusCode,
                    'durationMs' => $durationMs,
                    'requestSize' => (int) ($request->headers->get('Content-Length') ?? strlen((string) $request->getContent())),
                    'responseSize' => strlen((string) $response->getContent()),
                ]);
                if ($spanId !== null) {
                    $sdk->setSpanTag($spanId, 'http.status_code', (string) $statusCode);
                    $sdk->finishSpan($spanId, $statusCode >= 500 ? 'error' : 'ok');
                }
            } catch (Throwable $e) {
                // never break the request
            } finally {
                try {
                    $sdk->clearRequestContext();
                    $sdk->resetTrace();
                    $sdk->clearUser();
                } catch (Throwable $e) {
                    // best effort
                }
            }
        }

        return $response;
    }
}
