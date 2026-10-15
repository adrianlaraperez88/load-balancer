<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class HealthCheckCommandTest extends TestCase
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
        $app['config']->set('load-balancer.health_check.consecutive_failures', 2);
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_concurrent_health_check_marks_healthy_nodes_active()
    {
        $node1 = UpstreamNode::create(['name' => 'Node A', 'url' => 'http://node-a.local', 'is_active' => false]);
        $node2 = UpstreamNode::create(['name' => 'Node B', 'url' => 'http://node-b.local', 'is_active' => true]);

        Http::fake([
            'http://node-a.local*' => Http::response('OK', 200),
            'http://node-b.local*' => Http::response('OK', 200),
        ]);

        $this->artisan('loadbalancer:health-check')->assertExitCode(0);

        // Node A should be recovered and marked ACTIVE
        $this->assertTrue($node1->fresh()->is_active);
        $this->assertTrue($node2->fresh()->is_active);
    }

    public function test_health_check_requires_consecutive_failures_before_deactivating()
    {
        $node = UpstreamNode::create(['name' => 'Failing Node', 'url' => 'http://failing.local', 'is_active' => true]);

        Http::fake([
            'http://failing.local*' => Http::response('Internal Error', 500),
        ]);

        // 1st failure: soft failure, stays active
        $this->artisan('loadbalancer:health-check')->assertExitCode(0);
        $this->assertTrue($node->fresh()->is_active);

        // 2nd failure: reaches threshold (2), marked DEACTIVATED
        $this->artisan('loadbalancer:health-check')->assertExitCode(0);
        $this->assertFalse($node->fresh()->is_active);
    }
}
