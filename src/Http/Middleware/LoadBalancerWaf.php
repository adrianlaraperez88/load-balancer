<?php

namespace Isg\LoadBalancer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class LoadBalancerWaf
{
    /**
     * Handle an incoming request and throttle malicious IPs organically.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limit = config('load-balancer.waf.limit', 500); // 500 connections natively
        $window = config('load-balancer.waf.window_minutes', 1);

        $ip = $request->ip();

        if (RateLimiter::tooManyAttempts('lb_waf:' . $ip, $limit)) {
            return response()->json([
                'error' => 'Too Many Requests',
                'message' => 'Your connection has been intercepted by the Load Balancer WAF due to massive traversal volumes. Back off.'
            ], 429);
        }

        RateLimiter::hit('lb_waf:' . $ip, $window * 60);

        return $next($request);
    }
}
