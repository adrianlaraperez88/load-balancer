<?php

namespace Isg\LoadBalancer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Isg\LoadBalancer\Jobs\LogRequestMetricJob;
use Isg\LoadBalancer\Services\LoadBalancerManager;

class ProxyController extends Controller
{
    public function __construct(
        protected \Isg\LoadBalancer\Services\LoadBalancerManager $manager
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
        $triedNodes = [];
        $maxRetries = 3;
        $attempts = 0;

        while ($attempts < $maxRetries) {
            $node = $this->manager->resolveNode($request, $triedNodes);

            if (!$node) {
                return response()->json([
                    'error' => 'Service Unavailable',
                    'message' => 'No active upstream nodes are currently available.'
                ], 503);
            }

            $path = $request->path();
            $targetUrl = rtrim($node->url, '/') . '/' . ltrim($path, '/');
            
            // SSRF Safety Check: Enforce HTTP/HTTPS properly
            $isSecure = str_starts_with(strtolower($targetUrl), 'https://');
            $isHttp = str_starts_with(strtolower($targetUrl), 'http://');

            if (!$isSecure && !$isHttp) {
                return response()->json([
                    'error' => 'Internal Server Error',
                    'message' => 'Upstream URL has an invalid schema.'
                ], 500);
            }

            // Production constraint: Reject unencrypted internal HTTP calls.
            if ($isHttp && app()->environment('production')) {
                return response()->json([
                    'error' => 'Internal Server Error',
                    'message' => 'Unencrypted HTTP upstream nodes are forbidden in production environments.'
                ], 500);
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
                $method = strtolower($request->getMethod());
                
                $headers = $request->headers->all();
                unset($headers['host']); // Prevent upstream Host collision
                
                $headers['X-Forwarded-For'] = $request->ip();
                $headers['X-Forwarded-Host'] = $request->getHost();
                $headers['X-Forwarded-Proto'] = $request->getScheme();
                
                $proxyResponse = Http::withHeaders($headers)
                    ->withOptions(['decode_content' => false]) // Stop automatic ungzip
                    ->timeout(10)
                    ->send($method, $targetUrl, [
                        'query' => $request->query(),
                        'body' => $request->getContent(),
                    ]);

                $statusCode = $proxyResponse->status();
                $body = $proxyResponse->body();
                $responseHeaders = $proxyResponse->headers();
                
                $response = response($body, $statusCode);
                $hopByHop = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'upgrade'];
                
                foreach ($responseHeaders as $key => $values) {
                    if (!in_array(strtolower($key), $hopByHop)) {
                        $response->header($key, $values[0] ?? '');
                    }
                }

                $this->manager->incrementNodeLoad($node->id);
                $this->logMetric($request, $node->id, $statusCode, $startTime);
                return $response;

            } catch (\Exception $e) {
                $this->manager->markNodeDown($node->id);
                $triedNodes[] = $node->id;
                $attempts++;
            }
        }

        $this->logMetric($request, null, 502, $startTime);
        return response()->json([
            'error' => 'Bad Gateway',
            'message' => 'Upstream nodes failed to respond after retries.'
        ], 502);
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

        // Defer pushing execution until after fastcgi_finish_request closes the browser lane
        LogRequestMetricJob::dispatchAfterResponse(
            $request->ip(),
            $nodeId,
            $request->getMethod(),
            $request->path(),
            $statusCode,
            $responseTimeMs
        );
    }
}
