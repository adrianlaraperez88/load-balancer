<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Http\Middleware\VerifyLoadBalancerSignature;
use Illuminate\Support\Facades\Route;

class VerifyLoadBalancerSignatureTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LoadBalancerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('load-balancer.signature.secret', 'test-shared-secret-12345');
    }

    public function test_rejects_requests_missing_signature_headers()
    {
        Route::get('/protected-backend', fn () => response()->json(['status' => 'ok']))
            ->middleware(VerifyLoadBalancerSignature::class);

        $response = $this->getJson('/protected-backend');
        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_rejects_invalid_signature()
    {
        Route::get('/protected-backend', fn () => response()->json(['status' => 'ok']))
            ->middleware(VerifyLoadBalancerSignature::class);

        $timestamp = (string) time();
        $response = $this->withHeaders([
            'X-LB-Signature' => 'invalid-hmac-hash',
            'X-LB-Timestamp' => $timestamp,
            'X-Request-Id' => 'req-12345',
        ])->getJson('/protected-backend');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Forbidden']);
    }

    public function test_rejects_expired_signature()
    {
        Route::get('/protected-backend', fn () => response()->json(['status' => 'ok']))
            ->middleware(VerifyLoadBalancerSignature::class);

        // Expired timestamp (1 hour ago)
        $timestamp = (string) (time() - 3600);
        $payload = "GET|protected-backend|{$timestamp}|req-12345";
        $signature = hash_hmac('sha256', $payload, 'test-shared-secret-12345');

        $response = $this->withHeaders([
            'X-LB-Signature' => $signature,
            'X-LB-Timestamp' => $timestamp,
            'X-Request-Id' => 'req-12345',
        ])->getJson('/protected-backend');

        $response->assertStatus(401);
    }

    public function test_accepts_valid_signature()
    {
        Route::get('/protected-backend', fn () => response()->json(['status' => 'ok']))
            ->middleware(VerifyLoadBalancerSignature::class);

        $timestamp = (string) time();
        $payload = "GET|protected-backend|{$timestamp}|req-12345";
        $signature = hash_hmac('sha256', $payload, 'test-shared-secret-12345');

        $response = $this->withHeaders([
            'X-LB-Signature' => $signature,
            'X-LB-Timestamp' => $timestamp,
            'X-Request-Id' => 'req-12345',
        ])->getJson('/protected-backend');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }
}
