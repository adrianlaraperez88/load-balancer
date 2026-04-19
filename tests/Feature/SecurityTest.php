<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

class SecurityTest extends TestCase
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
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_ssrf_safety_blocks_non_http_schemas()
    {
        // Malicious entry successfully injected into database somehow
        $maliciousNode = UpstreamNode::create([
            'name' => 'Bad Node', 
            'url' => 'file:///etc/passwd', 
            'is_active' => true
        ]);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // Assert 500 error due to SSRF schema checks
        $response = $this->get('/test-proxy');
        $response->assertStatus(500);
        $response->assertJson([
            'error' => 'Internal Server Error',
            'message' => 'Upstream URL has an invalid schema.'
        ]);
    }

    public function test_production_environment_rejects_http_schemas()
    {
        // Act as production environment natively
        $this->app['env'] = 'production';

        $node = UpstreamNode::create([
            'name' => 'Bad Node', 
            'url' => 'http://unencrypted.local', 
            'is_active' => true
        ]);

        Route::any('/test-proxy', [ProxyController::class, 'handle']);

        // Assert 500 error due to unencrypted production check
        $response = $this->get('/test-proxy');
        $response->assertStatus(500);
        $response->assertJson([
            'error' => 'Internal Server Error',
            'message' => 'Unencrypted HTTP upstream nodes are forbidden in production environments.'
        ]);
    }
}
