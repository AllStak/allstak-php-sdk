<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\AllStak;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that captures every outbound HTTP request made through a
 * Guzzle handler stack and records it to AllStak's `/ingest/v1/http-requests`
 * with direction=outbound.
 *
 * Laravel's `Http::` facade uses Guzzle under the hood, so registering this
 * middleware on the shared Guzzle handler stack captures Http::get(...),
 * Http::post(...), etc. without any per-call instrumentation.
 *
 * Skips requests to AllStak's own ingest host to avoid recursion.
 *
 * Manual usage:
 *     $stack = \GuzzleHttp\HandlerStack::create();
 *     $stack->push(AllStakGuzzleMiddleware::forAllStak($allstak));
 *     $client = new \GuzzleHttp\Client(['handler' => $stack]);
 *
 * Automatic Laravel usage: registered globally by AllStakServiceProvider;
 * the framework's Http facade inherits the handler stack and gets capture
 * for free.
 */
final class AllStakGuzzleMiddleware
{
    /**
     * Factory that returns a Guzzle middleware callable capturing every request.
     *
     * @return callable(callable): callable
     */
    public static function forAllStak(AllStak $sdk): callable
    {
        $ownHostHash = rtrim($sdk->getOptions()->host, '/');

        return function (callable $handler) use ($sdk, $ownHostHash): callable {
            return function (RequestInterface $request, array $options) use ($handler, $sdk, $ownHostHash) {
                $start = microtime(true);
                $uri = (string)$request->getUri();
                $isOwnIngest = $ownHostHash && str_starts_with($uri, $ownHostHash);

                $onSuccess = function (ResponseInterface $response) use ($sdk, $request, $start, $isOwnIngest) {
                    if (!$isOwnIngest) {
                        try {
                            $sdk->captureHttpRequest([
                                'direction'   => 'outbound',
                                'method'      => $request->getMethod(),
                                'host'        => $request->getUri()->getHost()
                                                . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : ''),
                                'path'        => $request->getUri()->getPath() ?: '/',
                                'statusCode'  => $response->getStatusCode(),
                                'durationMs'  => (int)((microtime(true) - $start) * 1000),
                                'requestSize' => $request->getBody()->getSize() ?? 0,
                                'responseSize'=> $response->getBody()->getSize() ?? 0,
                            ]);
                        } catch (\Throwable $e) {
                            // never break the host
                        }
                    }
                    return $response;
                };

                $onFailure = function (\Throwable $reason) use ($sdk, $request, $start, $isOwnIngest) {
                    if (!$isOwnIngest) {
                        try {
                            $sdk->captureHttpRequest([
                                'direction'   => 'outbound',
                                'method'      => $request->getMethod(),
                                'host'        => $request->getUri()->getHost(),
                                'path'        => $request->getUri()->getPath() ?: '/',
                                'statusCode'  => 0,
                                'durationMs'  => (int)((microtime(true) - $start) * 1000),
                                'requestSize' => 0,
                                'responseSize'=> 0,
                            ]);
                        } catch (\Throwable $e) {
                            // never break the host
                        }
                    }
                    throw $reason;
                };

                return $handler($request, $options)->then($onSuccess, $onFailure);
            };
        };
    }
}
