<?php

namespace Isg\LoadBalancer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the metrics endpoint, which reveals internal backend URLs.
 * Disabled => 404. Every configured check (token, IP allowlist) must pass.
 * Nothing configured => deny.
 */
class ProtectMetricsEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('load-balancer.metrics', []);

        if (!($config['enabled'] ?? false)) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $token = (string) ($config['token'] ?? '');
        $allowedIps = array_values(array_filter((array) ($config['allowed_ips'] ?? [])));

        if ($token === '' && $allowedIps === []) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $provided = (string) ($request->bearerToken() ?: $request->header('X-Metrics-Secret', ''));
        if ($token !== '' && !hash_equals($token, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Supports single IPs and CIDR ranges (e.g. 10.0.0.0/24).
        if ($allowedIps !== [] && !IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
