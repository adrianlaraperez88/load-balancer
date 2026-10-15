<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Models\IpRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NodeManagementAPITest extends TestCase
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
        $app['config']->set('load-balancer.database_connection', 'sqlite');

        // Enable management endpoints and set auth token
        $app['config']->set('load-balancer.metrics.enabled', true);
        $app['config']->set('load-balancer.metrics.token', 'test-admin-secret-token');
    }

    public function test_can_list_nodes_via_api(): void
    {
        UpstreamNode::create(['name' => 'app01', 'url' => 'http://10.0.0.11:8000', 'weight' => 1]);
        UpstreamNode::create(['name' => 'app02', 'url' => 'http://10.0.0.12:8000', 'weight' => 2]);

        $response = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->getJson('/api/load-balancer/nodes');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('nodes.0.name', 'app01');
    }

    public function test_can_create_node_via_api(): void
    {
        $payload = [
            'name' => 'app03_compute',
            'url' => 'http://10.0.0.13:8000',
            'weight' => 5,
            'is_primary' => true,
        ];

        $response = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->postJson('/api/load-balancer/nodes', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('node.name', 'app03_compute')
            ->assertJsonPath('node.weight', 5)
            ->assertJsonPath('node.is_primary', true);

        $this->assertDatabaseHas('upstream_nodes', [
            'name' => 'app03_compute',
            'url' => 'http://10.0.0.13:8000',
        ]);
    }

    public function test_create_node_rejects_invalid_url_and_credentials(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->postJson('/api/load-balancer/nodes', [
                'name' => 'bad_node',
                'url' => 'ftp://malicious.com',
            ]);

        $response->assertStatus(422);

        $credResponse = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->postJson('/api/load-balancer/nodes', [
                'name' => 'bad_node',
                'url' => 'http://admin:secret@10.0.0.1:8000',
            ]);

        $credResponse->assertStatus(422);
    }

    public function test_can_toggle_node_active_status(): void
    {
        $node = UpstreamNode::create(['name' => 'app01', 'url' => 'http://10.0.0.11:8000', 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->patchJson("/api/load-balancer/nodes/{$node->id}/toggle");

        $response->assertStatus(200)
            ->assertJsonPath('node.is_active', false);

        $this->assertFalse($node->fresh()->is_active);
    }

    public function test_can_delete_node(): void
    {
        $node = UpstreamNode::create(['name' => 'app01', 'url' => 'http://10.0.0.11:8000']);

        $response = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->deleteJson("/api/load-balancer/nodes/{$node->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('upstream_nodes', ['id' => $node->id]);
    }

    public function test_can_manage_ip_rules(): void
    {
        $node = UpstreamNode::create(['name' => 'app01', 'url' => 'http://10.0.0.11:8000']);

        $postRes = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->postJson('/api/load-balancer/ip-rules', [
                'ip_address' => '192.168.1.100',
                'upstream_node_id' => $node->id,
            ]);

        $postRes->assertStatus(201)
            ->assertJsonPath('rule.ip_address', '192.168.1.100');

        $listRes = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->getJson('/api/load-balancer/ip-rules');

        $listRes->assertStatus(200)
            ->assertJsonPath('count', 1);

        $ruleId = $postRes->json('rule.id');
        $delRes = $this->withHeader('Authorization', 'Bearer test-admin-secret-token')
            ->deleteJson("/api/load-balancer/ip-rules/{$ruleId}");

        $delRes->assertStatus(200);
        $this->assertDatabaseMissing('ip_rules', ['id' => $ruleId]);
    }
}
