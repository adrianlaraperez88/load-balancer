<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Illuminate\Http\Request;
use Isg\LoadBalancer\Http\Middleware\VerifyLoadBalancerSignature;
use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;

class KeyRotationTest extends TestCase
{
    protected VerifyLoadBalancerSignature $middleware;

    protected function getPackageProviders($app)
    {
        return [
            LoadBalancerServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyLoadBalancerSignature();

        config([
            'load-balancer.signature.secret' => 'active-key-v2-super-secret-key-32-bytes',
            'load-balancer.signature.grace_secrets' => [
                'old-key-v1-super-secret-key-32-bytes',
            ],
            'load-balancer.signature.keys' => [
                'v1' => 'old-key-v1-super-secret-key-32-bytes',
                'v2' => 'active-key-v2-super-secret-key-32-bytes',
            ],
            'load-balancer.signature.tolerance_seconds' => 300,
        ]);
    }

    public function test_accepts_signature_signed_with_active_key()
    {
        $timestamp = (string) time();
        $requestId = 'req-active-test-01';
        $payload = "GET|api/workload|{$timestamp}|{$requestId}";
        $signature = hash_hmac('sha256', $payload, 'active-key-v2-super-secret-key-32-bytes');

        $request = Request::create('/api/workload', 'GET');
        $request->headers->set('x-lb-signature', $signature);
        $request->headers->set('x-lb-timestamp', $timestamp);
        $request->headers->set('x-request-id', $requestId);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'ok']);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_accepts_signature_signed_with_grace_period_key()
    {
        $timestamp = (string) time();
        $requestId = 'req-grace-test-02';
        $payload = "GET|api/workload|{$timestamp}|{$requestId}";
        // Signed with OLD key v1 during rotation:
        $signature = hash_hmac('sha256', $payload, 'old-key-v1-super-secret-key-32-bytes');

        $request = Request::create('/api/workload', 'GET');
        $request->headers->set('x-lb-signature', $signature);
        $request->headers->set('x-lb-timestamp', $timestamp);
        $request->headers->set('x-request-id', $requestId);
        $request->headers->set('x-lb-key-id', 'v1');

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'ok']);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_rejects_signature_signed_with_unknown_key()
    {
        $timestamp = (string) time();
        $requestId = 'req-unknown-key-03';
        $payload = "GET|api/workload|{$timestamp}|{$requestId}";
        $signature = hash_hmac('sha256', $payload, 'some-unauthorized-secret-key');

        $request = Request::create('/api/workload', 'GET');
        $request->headers->set('x-lb-signature', $signature);
        $request->headers->set('x-lb-timestamp', $timestamp);
        $request->headers->set('x-request-id', $requestId);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'ok']);
        });

        $this->assertEquals(403, $response->getStatusCode());
    }
}
