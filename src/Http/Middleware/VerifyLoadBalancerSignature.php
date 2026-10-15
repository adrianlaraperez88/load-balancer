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
        $secrets = [];
        if ($secret) {
            $secrets[] = $secret;
        }

        $keyId = (string) $request->headers->get('x-lb-key-id', '');
        $configuredKeys = (array) config('load-balancer.signature.keys', []);
        if ($keyId !== '' && isset($configuredKeys[$keyId])) {
            array_unshift($secrets, $configuredKeys[$keyId]);
        }

        foreach ((array) config('load-balancer.signature.grace_secrets', []) as $graceKey) {
            if ($graceKey && !in_array($graceKey, $secrets, true)) {
                $secrets[] = $graceKey;
            }
        }

        if (empty($secrets)) {
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

        // Calculate expected HMAC across allowed rotation keys
        $method = strtoupper($request->getMethod());
        $path = $request->path();
        $payload = "{$method}|{$path}|{$timestamp}|{$requestId}";

        $isValid = false;
        foreach ($secrets as $keyToTest) {
            $expectedSignature = hash_hmac('sha256', $payload, $keyToTest);
            if (hash_equals($expectedSignature, $signature)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Invalid load balancer signature.',
            ], 403);
        }

        // OWASP A02 & A07: Atomic Nonce Anti-Replay Defense
        // Ensures identical signed requests cannot be replayed inside the tolerance window
        $nonceKey = 'lb_seen_nonce_' . sha1($requestId);
        if (!\Illuminate\Support\Facades\Cache::add($nonceKey, true, $tolerance)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Load balancer signature replay detected (nonce already processed).',
            ], 401);
        }

        return $next($request);
    }
}
