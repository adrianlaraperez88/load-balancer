<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Models\IpRule;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

class IpRoutingTest extends TestCase
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
        
        $app['config']->set('load-balancer.mode', 'redirect');
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_explicit_ip_rules_override_strategies()
    {
        $node1 = UpstreamNode::create(['name' => 'General', 'url' => 'http://general.local', 'is_active' => true]);
        $node2 = UpstreamNode::create(['name' => 'Special', 'url' => 'http://special.local', 'is_active' => true]);

        IpRule::create([
            'ip_address' => '127.0.0.1',
            'upstream_node_id' => $node2->id,
        ]);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // Since we are running local tests, IP is '127.0.0.1' by default in Laravel
        $response = $this->get('/test-proxy');

        // It should bypass strategy and map explicitly to Node 2
        $response->assertStatus(302);
        $response->assertRedirect('http://special.local/test-proxy');
    }

    public function test_sticky_sessions_hash_ip_consistently()
    {
        config(['load-balancer.sticky_sessions' => true]);

        $node1 = UpstreamNode::create(['name' => 'Node A', 'url' => 'http://node-a.local', 'is_active' => true]);
        $node2 = UpstreamNode::create(['name' => 'Node B', 'url' => 'http://node-b.local', 'is_active' => true]);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        $response1 = $this->get('/test-proxy');
        $response1->assertStatus(302);
        
        // Hashing math: $hash = abs(crc32('127.0.0.1')) % 2
        $redirectUrl1 = $response1->headers->get('Location');

        // Execute exactly again from the same IP, it MUST map to the exact same URL consistently
        $response2 = $this->get('/test-proxy');
        $redirectUrl2 = $response2->headers->get('Location');

        $this->assertEquals($redirectUrl1, $redirectUrl2);
    }
}
