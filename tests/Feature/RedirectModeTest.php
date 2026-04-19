<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Queue;
use Isg\LoadBalancer\Jobs\LogRequestMetricJob;
use Illuminate\Support\Facades\Cache;

class RedirectModeTest extends TestCase
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
        
        $app['config']->set('load-balancer.mode', 'redirect');
        $app['config']->set('load-balancer.default_strategy', 'least_redirects');
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_redirect_mode_uses_least_redirects_strategy_and_returns_302()
    {
        Queue::fake();

        // Node A has 5 redirects tracked
        $nodeA = UpstreamNode::create([
            'name' => 'Node A',
            'url' => 'http://node-a.local',
            'weight' => 1,
            'is_active' => true,
        ]);
        Cache::put('node_' . $nodeA->id . '_redirects_load', 5);

        // Node B has 0 redirects tracked
        $nodeB = UpstreamNode::create([
            'name' => 'Node B',
            'url' => 'http://node-b.local',
            'weight' => 1,
            'is_active' => true,
        ]);
        Cache::put('node_' . $nodeB->id . '_redirects_load', 0);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // Because Node B has least redirects (0), traffic should go to Node B
        $response = $this->get('/test-proxy?query=yes');

        // Assert we get a 302 redirect directly instead of a proxy response
        $response->assertStatus(302);
        $response->assertRedirect('http://node-b.local/test-proxy?query=yes');

        // Assert Node B's redirect load was incremented
        $this->assertEquals(1, Cache::get('node_' . $nodeB->id . '_redirects_load'));

        Queue::assertPushed(LogRequestMetricJob::class, 1);
    }

    public function test_least_redirects_strategy_updates_load_on_successive_calls()
    {
        Queue::fake();

        $nodeB = UpstreamNode::create([
            'name' => 'Node B',
            'url' => 'http://node-b.local',
            'weight' => 1,
            'is_active' => true,
        ]);
        Cache::put('node_' . $nodeB->id . '_redirects_load', 1);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // Fire again
        $this->get('/test-proxy');
        $this->assertEquals(2, Cache::get('node_' . $nodeB->id . '_redirects_load'));

        Queue::assertPushed(LogRequestMetricJob::class, 1);
    }
}
