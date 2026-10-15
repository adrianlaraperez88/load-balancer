<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
use Isg\LoadBalancer\Http\Middleware\VerifyLoadBalancerSignature;
use Isg\LoadBalancer\Support\HeaderSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class OwaspHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            LoadBalancerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('load-balancer.database_connection', 'sqlite');
        $app['config']->set('load-balancer.mode', 'proxy');
        $app['config']->set('load-balancer.default_strategy', 'round_robin');
        $app['config']->set('load-balancer.metrics.enabled', true);
        $app['config']->set('load-balancer.metrics.token', 'test-admin-token');
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_cloud_metadata_ssrf_is_blocked_by_proxy()
    {
        UpstreamNode::create([
            'name' => 'Metadata Target',
            'url' => 'http://169.254.169.254:80',
            'is_active' => true,
        ]);

        Route::any('/test-proxy/{any?}', [ProxyController::class, 'handle'])->where('any', '.*');

        $response = $this->get('/test-proxy/latest/meta-data');
        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Forbidden',
            'message' => 'Upstream target points to a forbidden metadata or link-local address.',
        ]);
    }

    public function test_cloud_metadata_cannot_be_registered_as_upstream_node()
    {
        $response = $this->withHeader('Authorization', 'Bearer test-admin-token')
            ->postJson('/api/load-balancer/nodes', [
                'name' => 'Malicious IMDS Node',
                'url' => 'http://169.254.169.254/latest/meta-data/',
                'weight' => 1,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot target cloud metadata', $response->json('message'));
    }

    public function test_crlf_header_injection_is_sanitized_for_upstream()
    {
        $sanitizer = new HeaderSanitizer();
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Custom-Injected', "valid-value\r\nInjected-Header: evil\nAnother: bad");

        $forwarded = $sanitizer->forUpstream($request, 'test-request-id-12345678');

        $this->assertArrayHasKey('x-custom-injected', $forwarded);
        $cleanValue = $forwarded['x-custom-injected'][0];
        $this->assertStringNotContainsString("\r", $cleanValue);
        $this->assertStringNotContainsString("\n", $cleanValue);
        $this->assertEquals('valid-valueInjected-Header: evilAnother: bad', $cleanValue);
    }

    public function test_signature_replay_attack_is_rejected_by_nonce_cache()
    {
        Cache::flush();
        config([
            'load-balancer.signature.enabled' => true,
            'load-balancer.signature.secret' => 'super-secret-key-for-replay-testing',
            'load-balancer.signature.tolerance_seconds' => 300,
        ]);

        Route::middleware(VerifyLoadBalancerSignature::class)->get('/protected-action', function () {
            return response()->json(['status' => 'success']);
        });

        $now = time();
        $requestId = 'replay-nonce-uuid-' . uniqid();
        $payload = "GET|protected-action|{$now}|{$requestId}";
        $signature = hash_hmac('sha256', $payload, 'super-secret-key-for-replay-testing');

        // First attempt with this nonce: Should SUCCEED (200 OK)
        $response1 = $this->withHeaders([
            'X-LB-Signature' => $signature,
            'X-LB-Timestamp' => (string) $now,
            'X-Request-Id' => $requestId,
        ])->get('/protected-action');
        $response1->assertStatus(200);

        // Replay attempt with the EXACT SAME nonce within tolerance window: Should FAIL (401 Unauthorized)
        $response2 = $this->withHeaders([
            'X-LB-Signature' => $signature,
            'X-LB-Timestamp' => (string) $now,
            'X-Request-Id' => $requestId,
        ])->get('/protected-action');
        $response2->assertStatus(401);
        $this->assertEquals('Load balancer signature replay detected (nonce already processed).', $response2->json('message'));
    }

    public function test_proxy_response_includes_nosniff_and_sameorigin_headers()
    {
        UpstreamNode::create([
            'name' => 'Header Test Node',
            'url' => 'http://test-server.local',
            'is_active' => true,
        ]);

        Http::fake([
            'http://test-server.local/*' => Http::response('OK', 200, ['Content-Type' => 'text/html']),
        ]);

        Route::any('/api/{any?}', [ProxyController::class, 'handle'])->where('any', '.*');

        $response = $this->get('/api/resource');
        $response->assertStatus(200);
        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertHeader('x-frame-options', 'SAMEORIGIN');
    }
}
