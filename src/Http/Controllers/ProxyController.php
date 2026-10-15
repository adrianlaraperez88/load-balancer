<?php

namespace Isg\LoadBalancer\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Isg\LoadBalancer\Jobs\LogRequestMetricJob;
use Isg\LoadBalancer\Services\LoadBalancerManager;
use Isg\LoadBalancer\Support\HeaderSanitizer;
use Symfony\Component\HttpFoundation\Response;

class ProxyController extends Controller
{
    public function __construct(
        protected LoadBalancerManager $manager,
        protected HeaderSanitizer $sanitizer
    ) {
        if (config('load-balancer.waf.enabled', true)) {
            if ($limiter = config('load-balancer.waf.limiter')) {
                // If the user specifies an existing Laravel RateLimiter string, mechanically use Laravel's throttling natively!
                $this->middleware("throttle:{$limiter}");
            } else {
                $this->middleware(\Isg\LoadBalancer\Http\Middleware\LoadBalancerWaf::class);
            }
        }
    }

    public function handle(Request $request)
    {
        $startTime = microtime(true);
        $mode = config('load-balancer.mode', 'redirect');
        $method = strtoupper($request->getMethod());
        $requestId = $this->sanitizer->requestId($request);

        if ($rejection = $this->guardPath($request)) {
            return $rejection;
        }

        $body = null;
        if ($mode !== 'redirect') {
            $maxBody = (int) config('load-balancer.max_body_bytes', 10 * 1024 * 1024);

            // Reject on the declared size first so oversized uploads are never buffered.
            if ($maxBody > 0 && (int) $request->headers->get('content-length', 0) > $maxBody) {
                return $this->error(413, 'Payload Too Large', 'Request body exceeds the allowed size.');
            }

            $body = $request->getContent();
            if ($maxBody > 0 && strlen($body) > $maxBody) {
                return $this->error(413, 'Payload Too Large', 'Request body exceeds the allowed size.');
            }
        }

        // --- Gateway Response Microcaching ---
        $cacheConfig = config('load-balancer.cache', []);
        $cacheEnabled = !empty($cacheConfig['enabled']);
        $cacheKey = null;

        if ($cacheEnabled && in_array($method, $cacheConfig['methods'] ?? ['GET', 'HEAD'], true)) {
            if (!$request->headers->has('authorization') && !$request->cookies->has('app03_gateway_session')) {
                $cacheKey = 'lb_proxy_cache_' . sha1($method . '|' . $request->path() . '|' . serialize($request->query()));
                $cached = \Illuminate\Support\Facades\Cache::store($cacheConfig['store'] ?? null)->get($cacheKey);
                if ($cached !== null) {
                    $cachedResponse = response($cached['body'], $cached['status']);
                    foreach ($cached['headers'] as $name => $values) {
                        $cachedResponse->headers->set($name, $values);
                    }
                    $cachedResponse->headers->set('x-cache', 'HIT');
                    $cachedResponse->headers->set('x-request-id', $requestId);
                    return $cachedResponse;
                }
            }
        }

        $triedNodes = [];
        $maxAttempts = max(1, (int) config('load-balancer.retry.max_attempts', 3));
        $idempotent = array_map('strtoupper', (array) config('load-balancer.retry.idempotent_methods', ['GET', 'HEAD', 'OPTIONS']));
        $lastWasTimeout = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $node = $this->manager->resolveNode($request, $triedNodes);

            if (!$node) {
                if ($triedNodes !== []) {
                    break; // every node tried; fall through to 502/504
                }

                return $this->error(503, 'Service Unavailable', 'No active upstream nodes are currently available.');
            }

            $path = $request->path();
            $targetUrl = rtrim($node->url, '/') . '/' . ltrim($path, '/');

            // SSRF Safety Check: Enforce HTTP/HTTPS properly
            $isSecure = str_starts_with(strtolower($targetUrl), 'https://');
            $isHttp = str_starts_with(strtolower($targetUrl), 'http://');

            if (!$isSecure && !$isHttp) {
                return $this->error(500, 'Internal Server Error', 'Upstream URL has an invalid schema.');
            }

            // Production constraint: Reject unencrypted internal HTTP calls unless explicitly allowed (e.g., internal VPC/cluster)
            $enforceHttps = config('load-balancer.enforce_https', true);
            if ($isHttp && app()->environment('production') && $enforceHttps) {
                return $this->error(500, 'Internal Server Error', 'Unencrypted HTTP upstream nodes are forbidden in production environments.');
            }

            if ($queryString = $request->getQueryString()) {
                $targetUrl .= '?' . $queryString;
            }

            if ($mode === 'redirect') {
                $this->manager->incrementNodeLoad($node->id);
                $this->logMetric($request, $node->id, 302, $startTime);
                return redirect()->to($targetUrl, 302);
            }

            try {
                $proxyResponse = $this->send($request, $method, $targetUrl, $body, $requestId);
            } catch (\Exception $e) {
                $connectPhase = $this->isConnectFailure($e);
                $lastWasTimeout = $this->isTimeout($e) && !$connectPhase;

                // Only a failure to connect counts against the node: a slow
                // node is not a dead node (prevents cascading 503 storms).
                if ($connectPhase) {
                    $this->manager->recordConnectFailure($node->id);
                }

                Log::warning('Load balancer upstream failure', [
                    'request_id' => $requestId,
                    'node_id' => $node->id,
                    'method' => $method,
                    'path' => Str::limit($path, 255),
                    'attempt' => $attempt,
                    'connect_phase' => $connectPhase,
                    'error' => get_class($e) . ': ' . Str::limit($e->getMessage(), 300),
                ]);

                $triedNodes[] = $node->id;

                // Never replay a request the backend may already have received
                // unless the method is idempotent (no duplicate POST/PUT/DELETE).
                if (!$connectPhase && !in_array($method, $idempotent, true)) {
                    break;
                }

                continue;
            }

            $this->manager->incrementNodeLoad($node->id);
            $this->logMetric($request, $node->id, $proxyResponse->status(), $startTime);

            if ($cacheKey !== null && $proxyResponse->successful() && !config('load-balancer.stream_responses', false)) {
                \Illuminate\Support\Facades\Cache::store($cacheConfig['store'] ?? null)->put($cacheKey, [
                    'body' => $proxyResponse->body(),
                    'status' => $proxyResponse->status(),
                    'headers' => $this->sanitizer->forClient($proxyResponse->headers()),
                ], now()->addSeconds((int) ($cacheConfig['ttl_seconds'] ?? 5)));
            }

            $response = $this->toClientResponse($proxyResponse, $requestId);
            if ($cacheKey !== null) {
                $response->headers->set('x-cache', 'MISS');
            }
            return $response;
        }

        $status = $lastWasTimeout ? 504 : 502;
        $this->logMetric($request, null, $status, $startTime);

        return $lastWasTimeout
            ? $this->error(504, 'Gateway Timeout', 'Upstream node did not respond in time.', $requestId)
            : $this->error(502, 'Bad Gateway', 'Upstream nodes failed to respond after retries.', $requestId);
    }

    protected function send(Request $request, string $method, string $targetUrl, ?string $body, string $requestId): ClientResponse
    {
        $options = [];
        if ($body !== null && $body !== '') {
            $options['body'] = $body;
        }

        $clientOptions = [
            'decode_content' => false, // Stop automatic ungzip
            'verify' => config('load-balancer.tls_verify', true),
            'allow_redirects' => false, // Redirects must reach the UI untouched
            'curl' => [
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 120,
                CURLOPT_TCP_KEEPINTVL => 60,
            ],
        ];

        if (config('load-balancer.stream_responses', false)) {
            $clientOptions['stream'] = true;
        }

        return Http::withHeaders($this->sanitizer->forUpstream($request, $requestId))
            ->withOptions($clientOptions)
            ->connectTimeout(max(1, (int) config('load-balancer.connect_timeout', 2)))
            ->timeout(max(1, (int) config('load-balancer.timeout', 30)))
            ->send(strtolower($method), $targetUrl, $options);
    }

    protected function toClientResponse(ClientResponse $proxyResponse, string $requestId): Response
    {
        $headers = $this->sanitizer->forClient($proxyResponse->headers());
        $headers['x-request-id'] = [$requestId];
        $status = $proxyResponse->status();

        if (config('load-balancer.stream_responses', false)) {
            $stream = $proxyResponse->toPsrResponse()->getBody();

            $response = response()->stream(function () use ($stream) {
                while (!$stream->eof()) {
                    echo $stream->read(8192);
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }, $status);
        } else {
            $response = response($proxyResponse->body(), $status);
        }

        foreach ($headers as $name => $values) {
            try {
                // Passing the full array keeps every value (multiple Set-Cookie).
                $response->headers->set($name, $values);
            } catch (\InvalidArgumentException $e) {
                // Malformed upstream header (e.g. unparsable cookie): drop it.
            }
        }

        return $response;
    }

    /**
     * Reject path traversal and paths on the blocked list before they ever
     * reach a backend. IIS treats "\" as a separator, so it is rejected too.
     */
    protected function guardPath(Request $request): ?Response
    {
        $raw = $request->path();
        $decoded = strtolower(rawurldecode(rawurldecode($raw)));

        if (
            str_contains($decoded, "\0")
            || str_contains($decoded, '\\')
            || str_contains($decoded, ':') // Windows Alternate Data Streams (::$DATA)
            || preg_match('#(^|/)\.\.?(/|$)#', $decoded) === 1
            || preg_match('#[\. ](/|$)#', $decoded) === 1 // Windows trailing dot / space stripping
            || preg_match('#~[0-9]#', $decoded) === 1 // Windows 8.3 short filename notation (e.g. web~1.con)
        ) {
            return $this->error(400, 'Bad Request', 'Malformed request path.');
        }

        $candidate = trim($decoded, '/');
        foreach ((array) config('load-balancer.blocked_paths', []) as $pattern) {
            if (Str::is(strtolower(trim($pattern, '/')), $candidate)) {
                return $this->error(404, 'Not Found', 'The requested resource was not found.');
            }
        }

        return null;
    }

    /**
     * True when the request provably never reached the backend
     * (DNS failure, refused connection, connect timeout, TLS handshake).
     */
    protected function isConnectFailure(\Throwable $e): bool
    {
        if (!$e instanceof ConnectionException) {
            return false;
        }

        $message = $e->getMessage();

        if (preg_match('/cURL error (6|7|35|60)\b/', $message) === 1) {
            return true;
        }

        return preg_match('/Connection timed out|Connection timeout|Resolving timed out|Connection refused|Could not resolve host|Failed to connect/i', $message) === 1;
    }

    protected function isTimeout(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            && preg_match('/cURL error 28\b|timed out/i', $e->getMessage()) === 1;
    }

    protected function error(int $status, string $error, string $message, ?string $requestId = null): Response
    {
        $response = response()->json(['error' => $error, 'message' => $message], $status);

        if ($requestId) {
            $response->headers->set('x-request-id', $requestId);
        }

        return $response;
    }

    protected function logMetric(Request $request, ?int $nodeId, int $statusCode, float $startTime)
    {
        if (!config('load-balancer.logging.enabled', true)) {
            return;
        }

        // Fast statistical probability logic limits backend Queue IO on high-scale systems
        $sampleRate = config('load-balancer.logging.sample_rate', 1.0);
        if ($sampleRate < 1.0 && (mt_rand(1, 10000) / 10000) > $sampleRate) {
            return; // Skip logging this interaction probabilistically
        }

        $endTime = microtime(true);
        $responseTimeMs = (int) (($endTime - $startTime) * 1000);

        // Runs in terminate(). On PHP-FPM the client is released first via
        // fastcgi_finish_request; on IIS FastCGI (php-cgi.exe) it is NOT, so
        // keep sample_rate low on IIS to avoid adding DB latency to responses.
        // Values are clamped to the column sizes in request_metrics.
        LogRequestMetricJob::dispatchAfterResponse(
            (string) $request->ip(),
            $nodeId,
            substr($request->getMethod(), 0, 10),
            substr($request->path(), 0, 2048),
            $statusCode,
            $responseTimeMs
        );
    }
}
