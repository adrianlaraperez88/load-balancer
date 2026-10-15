<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MetricsProtectionTest extends TestCase
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

    public function test_metrics_endpoint_is_disabled_by_default_returning_404()
    {
        $response = $this->getJson('/api/load-balancer/metrics');

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Not Found']);
    }

    public function test_metrics_endpoint_returns_403_when_enabled_without_token_or_ips()
    {
        config(['load-balancer.metrics.enabled' => true]);

        $response = $this->getJson('/api/load-balancer/metrics');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Forbidden']);
    }

    public function test_metrics_endpoint_enforces_bearer_token()
    {
        config([
            'load-balancer.metrics.enabled' => true,
            'load-balancer.metrics.token' => 'super-secret-metrics-token',
        ]);

        // Missing / wrong token -> 401
        $this->getJson('/api/load-balancer/metrics')->assertStatus(401);
        $this->withToken('wrong-token')->getJson('/api/load-balancer/metrics')->assertStatus(401);

        // Correct token -> 200
        $response = $this->withToken('super-secret-metrics-token')->getJson('/api/load-balancer/metrics');
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'cluster_health', 'global_metrics', 'nodes']);
    }

    public function test_metrics_endpoint_enforces_ip_allowlist()
    {
        config([
            'load-balancer.metrics.enabled' => true,
            'load-balancer.metrics.token' => 'token123',
            'load-balancer.metrics.allowed_ips' => ['10.0.0.5', '192.168.1.0/24'],
        ]);

        // Wrong IP even with valid token -> 403
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withToken('token123')
            ->getJson('/api/load-balancer/metrics')
            ->assertStatus(403);

        // Allowed single IP -> 200
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withToken('token123')
            ->getJson('/api/load-balancer/metrics')
            ->assertStatus(200);

        // Allowed CIDR range IP -> 200
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.42'])
            ->withToken('token123')
            ->getJson('/api/load-balancer/metrics')
            ->assertStatus(200);
    }
}
