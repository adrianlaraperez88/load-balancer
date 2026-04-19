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
use Isg\LoadBalancer\Jobs\LogRequestMetricJob;

class ProxyTest extends TestCase
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
        
        $app['config']->set('load-balancer.mode', 'proxy');
        $app['config']->set('load-balancer.default_strategy', 'round_robin');
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_proxy_flow_resolves_and_forwards_request()
    {
        Queue::fake();

        // 1. Create upstream node
        UpstreamNode::create([
            'name' => 'Test Node',
            'url' => 'http://test-upstream.local',
            'weight' => 1,
            'is_active' => true,
        ]);

        // 2. Mock Http response from upstream
        Http::fake([
            'http://test-upstream.local/*' => Http::response(['data' => 'proxied content'], 200)
        ]);

        // 3. Register route for testing
        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // 4. Hit proxy
        $response = $this->get('/test-proxy');

        $response->assertStatus(200);
        $response->assertJson(['data' => 'proxied content']);

        // 5. Assert metric job dispatched
        Queue::assertPushed(LogRequestMetricJob::class, function ($job) {
             // Access protected properties using Reflection or just assert it pushed
             return true; 
        });
    }
}
