<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\RequestMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class PruneMetricsCommandTest extends TestCase
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
    }

    protected function defineDatabaseMigrations()
    {
        (require __DIR__.'/../../database/migrations/create_upstream_nodes_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_ip_rules_table.php.stub')->up();
        (require __DIR__.'/../../database/migrations/create_request_metrics_table.php.stub')->up();
    }

    public function test_prunes_metrics_older_than_specified_days()
    {
        // 1. Create old metrics (10 days old)
        RequestMetric::insert([
            ['incoming_ip' => '1.1.1.1', 'method' => 'GET', 'uri' => '/old1', 'status_code' => 200, 'response_time_ms' => 10, 'created_at' => Carbon::now()->subDays(10)],
            ['incoming_ip' => '1.1.1.2', 'method' => 'GET', 'uri' => '/old2', 'status_code' => 200, 'response_time_ms' => 12, 'created_at' => Carbon::now()->subDays(9)],
        ]);

        // 2. Create fresh metric (1 day old)
        RequestMetric::insert([
            ['incoming_ip' => '1.1.1.3', 'method' => 'GET', 'uri' => '/fresh', 'status_code' => 200, 'response_time_ms' => 15, 'created_at' => Carbon::now()->subDays(1)],
        ]);

        $this->assertEquals(3, RequestMetric::count());

        // 3. Run pruner with --days=7
        $this->artisan('loadbalancer:prune-metrics', ['--days' => 7, '--chunk' => 100])
            ->assertExitCode(0);

        // 4. Assert only 1 metric remains (the fresh one)
        $this->assertEquals(1, RequestMetric::count());
        $this->assertEquals('/fresh', RequestMetric::first()->uri);
    }
}
