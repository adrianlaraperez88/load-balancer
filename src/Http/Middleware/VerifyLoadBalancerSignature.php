<?php

namespace Isg\LoadBalancer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLoadBalancerSignature
{
    /**
     * Handle an incoming request on a backend server, ensuring it was signed by app03.
     */
    public function handle(Request $request, Closure $next, ?string $secret = null): Response
    {
        $secret = $secret ?: config('load-balancer.signature.secret');

        if (!$secret) {
            return response()->json([
                'error' => 'Server Configuration Error',
                'message' => 'Load balancer signature secret is not configured on this backend.',
            ], 500);
        }

        $sigHeader = strtolower((string) config('load-balancer.signature.header', 'X-LB-Signature'));
        $timeHeader = strtolower((string) config('load-balancer.signature.timestamp_header', 'X-LB-Timestamp'));

        $signature = (string) $request->headers->get($sigHeader, '');
        $timestamp = (string) $request->headers->get($timeHeader, '');
        $requestId = (string) $request->headers->get('x-request-id', '');

        if ($signature === '' || $timestamp === '' || $requestId === '') {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing required load balancer signature headers.',
            ], 401);
        }

        // Replay attack prevention: verify timestamp within configured tolerance
        $tolerance = max(10, (int) config('load-balancer.signature.tolerance_seconds', 300));
        $now = time();

        if (abs($now - (int) $timestamp) > $tolerance) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Load balancer signature timestamp has expired or is invalid.',
            ], 401);
        }

        // Calculate expected HMAC
        $method = strtoupper($request->getMethod());
        $path = $request->path();
        $payload = "{$method}|{$path}|{$timestamp}|{$requestId}";
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Invalid load balancer signature.',
            ], 403);
        }

        return $next($request);
    }
}
