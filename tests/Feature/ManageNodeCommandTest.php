<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManageNodeCommandTest extends TestCase
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

    public function test_can_add_node_via_command()
    {
        $this->artisan('loadbalancer:node', [
            'action' => 'add',
            '--name' => 'app01',
            '--url' => 'http://10.0.0.11:8000',
            '--weight' => 2,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('upstream_nodes', [
            'name' => 'app01',
            'url' => 'http://10.0.0.11:8000',
            'weight' => 2,
            'is_active' => true,
        ]);
    }

    public function test_rejects_invalid_or_credential_embedded_urls()
    {
        // Missing / malformed scheme
        $this->artisan('loadbalancer:node', [
            'action' => 'add',
            '--name' => 'bad1',
            '--url' => 'ftp://10.0.0.11',
        ])->assertExitCode(1);

        // Embedded credentials
        $this->artisan('loadbalancer:node', [
            'action' => 'add',
            '--name' => 'bad2',
            '--url' => 'http://admin:secret@10.0.0.11:8000',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('upstream_nodes', ['name' => 'bad1']);
        $this->assertDatabaseMissing('upstream_nodes', ['name' => 'bad2']);
    }

    public function test_can_list_nodes_via_command()
    {
        UpstreamNode::create([
            'name' => 'app02',
            'url' => 'http://10.0.0.12:8000',
            'weight' => 1,
            'is_active' => true,
        ]);

        $this->artisan('loadbalancer:node', ['action' => 'list'])
            ->expectsOutputToContain('app02')
            ->assertExitCode(0);
    }

    public function test_can_toggle_node_active_status()
    {
        $node = UpstreamNode::create([
            'name' => 'app03-worker',
            'url' => 'http://10.0.0.13:8000',
            'is_active' => true,
        ]);

        $this->artisan('loadbalancer:node', [
            'action' => 'toggle',
            '--id' => $node->id,
        ])->assertExitCode(0);

        $this->assertFalse($node->fresh()->is_active);
    }

    public function test_can_remove_node_via_command()
    {
        $node = UpstreamNode::create([
            'name' => 'to-delete',
            'url' => 'http://10.0.0.99:8000',
            'is_active' => true,
        ]);

        $this->artisan('loadbalancer:node', [
            'action' => 'remove',
            '--id' => $node->id,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('upstream_nodes', [
            'id' => $node->id,
        ]);
    }
}
