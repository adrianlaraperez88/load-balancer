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
        $app['config']->set('load-balancer.logging.enabled', true);
        $app['config']->set('load-balancer.logging.sample_rate', 1.0);
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

    public function test_proxy_forwards_query_parameters_without_duplication()
    {
        Queue::fake();

        UpstreamNode::create([
            'name' => 'Query Node',
            'url' => 'http://test-upstream.local',
            'weight' => 1,
            'is_active' => true,
        ]);

        $recordedUrl = null;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$recordedUrl) {
            $recordedUrl = $request->url();
            return Http::response(['success' => true], 200);
        });

        Route::any('/search', [ProxyController::class, 'handle']);

        $response = $this->get('/search?query=laravel&page=2');
        $response->assertStatus(200);

        // Verify the proxied URL contains single occurrences of the query parameters
        $this->assertNotNull($recordedUrl);
        $this->assertStringContainsString('http://test-upstream.local/search?', $recordedUrl);
        $this->assertStringContainsString('query=laravel', $recordedUrl);
        $this->assertStringContainsString('page=2', $recordedUrl);
        $this->assertSame(1, substr_count($recordedUrl, 'query=laravel'));
        $this->assertSame(1, substr_count($recordedUrl, 'page=2'));
    }

    public function test_route_macro_load_balancer_registers_properly()
    {
        Queue::fake();

        UpstreamNode::create([
            'name' => 'Macro Node',
            'url' => 'http://test-upstream.local',
            'weight' => 1,
            'is_active' => true,
        ]);

        Http::fake([
            'http://test-upstream.local/*' => Http::response(['macro' => 'working'], 200)
        ]);

        Route::loadBalancer('/gateway/{any?}');

        $response = $this->get('/gateway/users/42');
        $response->assertStatus(200);
        $response->assertJson(['macro' => 'working']);
    }
}

