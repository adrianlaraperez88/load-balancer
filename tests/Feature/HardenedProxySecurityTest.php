<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

class HardenedProxySecurityTest extends TestCase
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
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('load-balancer.mode', 'proxy');
        $app['config']->set('load-balancer.default_strategy', 'round_robin');
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_blocked_paths_return_404_before_reaching_upstream()
    {
        Queue::fake();

        UpstreamNode::create([
            'name' => 'Backend Node',
            'url' => 'http://backend.internal',
            'is_active' => true,
        ]);

        Route::any('/{any?}', [ProxyController::class, 'handle'])->where('any', '.*');

        // Test blocked patterns (.env, web.config, vendor, horizon, etc.)
        $this->get('/.env')->assertStatus(404);
        $this->get('/.env.production')->assertStatus(404);
        $this->get('/web.config')->assertStatus(404);
        $this->get('/vendor/autoload.php')->assertStatus(404);
        $this->get('/telescope/requests')->assertStatus(404);
        $this->get('/_ignition/execute-solution')->assertStatus(404);
    }

    public function test_path_traversal_and_backslash_rejected_with_400()
    {
        Queue::fake();

        UpstreamNode::create([
            'name' => 'Backend Node',
            'url' => 'http://backend.internal',
            'is_active' => true,
        ]);

        Route::any('/{any?}', [ProxyController::class, 'handle'])->where('any', '.*');

        $this->get('/api/../admin')->assertStatus(400);
        $this->get('/api%2f..%2fadmin')->assertStatus(400);
        $this->get('/api%5cusers')->assertStatus(400); // backslash in URL
        $this->get('/api/file.php::$DATA')->assertStatus(400); // Windows Alternate Data Streams
        $this->get('/api/file.php:stream')->assertStatus(400); // Colon in path
        $this->get('/api/web.config.')->assertStatus(400); // Windows trailing dot stripping
        $this->get('/api/web~1.con')->assertStatus(400); // Windows 8.3 short filename notation
    }

    public function test_payload_exceeding_max_body_bytes_returns_413()
    {
        Queue::fake();
        config(['load-balancer.max_body_bytes' => 100]); // 100 bytes limit for test

        UpstreamNode::create([
            'name' => 'Backend Node',
            'url' => 'http://backend.internal',
            'is_active' => true,
        ]);

        Route::any('/upload', [ProxyController::class, 'handle']);

        $oversizedBody = str_repeat('A', 150);

        $response = $this->call('POST', '/upload', [], [], [], [
            'CONTENT_LENGTH' => 150,
        ], $oversizedBody);

        $response->assertStatus(413);
        $response->assertJson(['error' => 'Payload Too Large']);
    }

    public function test_headers_are_sanitized_and_bearer_tokens_are_forwarded()
    {
        Queue::fake();

        UpstreamNode::create([
            'name' => 'Backend Node',
            'url' => 'http://backend.internal',
            'is_active' => true,
        ]);

        $capturedUpstreamHeaders = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$capturedUpstreamHeaders) {
            $capturedUpstreamHeaders = $request->headers();
            return Http::response([
                'status' => 'ok',
            ], 200, [
                'Set-Cookie' => ['session_id=abc123xyz; Path=/', 'remember_token=xyz789; Path=/'],
                'Server' => 'Microsoft-IIS/10.0',
                'X-Powered-By' => 'ASP.NET',
                'X-Custom-Backend' => 'App01',
            ]);
        });

        Route::any('/api/profile', [ProxyController::class, 'handle']);

        // Send request with spoofed client headers and a Bearer token
        $response = $this->withHeaders([
            'Authorization' => 'Bearer sanctum_token_12345',
            'X-Forwarded-For' => '1.2.3.4, 5.6.7.8', // Spoofed client IP
            'X-Original-URL' => '/admin',             // Spoofed original URL
            'Connection' => 'close',
        ])->get('/api/profile');

        $response->assertStatus(200);

        // 1. Inbound upstream verification:
        // Bearer token must pass through
        $this->assertEquals(['Bearer sanctum_token_12345'], $capturedUpstreamHeaders['authorization']);
        // Spoofed X-Original-URL must be stripped
        $this->assertArrayNotHasKey('x-original-url', $capturedUpstreamHeaders);
        // Authoritative X-Forwarded-For is set (127.0.0.1 from test client), not the spoofed IP
        $this->assertEquals(['127.0.0.1'], $capturedUpstreamHeaders['x-forwarded-for']);
        // X-Request-Id must be present
        $this->assertArrayHasKey('x-request-id', $capturedUpstreamHeaders);

        // 2. Outbound client response verification:
        // Both Set-Cookie headers must be preserved
        $cookies = $response->headers->all('set-cookie');
        $this->assertCount(2, $cookies);
        $this->assertStringContainsString('session_id=abc123xyz', $cookies[0]);
        $this->assertStringContainsString('remember_token=xyz789', $cookies[1]);

        // Backend fingerprinting headers must be stripped
        $this->assertNull($response->headers->get('Server'));
        $this->assertNull($response->headers->get('X-Powered-By'));
        $this->assertEquals('App01', $response->headers->get('X-Custom-Backend'));
    }

    public function test_circuit_breaker_fails_open_when_all_nodes_tripped()
    {
        Queue::fake();

        $node1 = UpstreamNode::create(['name' => 'Node 1', 'url' => 'http://node1.internal', 'is_active' => true]);
        $node2 = UpstreamNode::create(['name' => 'Node 2', 'url' => 'http://node2.internal', 'is_active' => true]);

        // Manually open circuit on both nodes
        app(\Isg\LoadBalancer\Services\LoadBalancerManager::class)->markNodeDown($node1->id);
        app(\Isg\LoadBalancer\Services\LoadBalancerManager::class)->markNodeDown($node2->id);

        Http::fake([
            'http://node1.internal/*' => Http::response(['data' => 'fallback served'], 200),
            'http://node2.internal/*' => Http::response(['data' => 'fallback served'], 200),
        ]);

        Route::any('/test-circuit', [ProxyController::class, 'handle']);

        // Even though both nodes are marked down, manager fails open instead of returning 503
        $response = $this->get('/test-circuit');
        $response->assertStatus(200);
        $response->assertJson(['data' => 'fallback served']);
    }
}
