<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;

use Illuminate\Support\Facades\Route;
use Isg\LoadBalancer\Http\Controllers\ProxyController;

class GatewayMicrocacheTest extends TestCase
{
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
        $app['config']->set('load-balancer.stream_responses', false);
        $app['config']->set('load-balancer.cache.enabled', true);
        $app['config']->set('load-balancer.cache.ttl_seconds', 5);
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::any('/api/{any?}', [ProxyController::class, 'handle']);
    }

    public function test_microcache_returns_miss_then_hit_for_idempotent_get()
    {
        Cache::flush();
        UpstreamNode::create(['name' => 'cache_node', 'url' => 'http://10.0.0.99:8000', 'weight' => 1, 'is_active' => true]);

        Http::fake([
            'http://10.0.0.99:8000/*' => Http::response(['message' => 'Fresh Backend Data'], 200, ['Content-Type' => 'application/json']),
        ]);

        // First call: Expect cache MISS
        $response1 = $this->get('/api/cached-data');
        $response1->assertStatus(200);
        $response1->assertHeader('x-cache', 'MISS');
        $this->assertEquals('Fresh Backend Data', $response1->json('message'));

        // Second call: Expect cache HIT (no upstream call made)
        $response2 = $this->get('/api/cached-data');
        $response2->assertStatus(200);
        $response2->assertHeader('x-cache', 'HIT');
        $this->assertEquals('Fresh Backend Data', $response2->json('message'));
    }

    public function test_microcache_bypassed_when_authorization_header_present()
    {
        Cache::flush();
        UpstreamNode::create(['name' => 'auth_cache_node', 'url' => 'http://10.0.0.99:8000', 'weight' => 1, 'is_active' => true]);

        Http::fake([
            'http://10.0.0.99:8000/*' => Http::response(['user_data' => 'private'], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer user-token')->get('/api/private-data');
        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('x-cache'));
    }
}
