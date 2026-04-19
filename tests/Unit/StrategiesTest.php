<?php

namespace Isg\LoadBalancer\Tests\Unit;

use Illuminate\Http\Request;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Strategies\RandomStrategy;
use Isg\LoadBalancer\Strategies\RoundRobinStrategy;
use Isg\LoadBalancer\Strategies\WeightedRandomStrategy;
use Isg\LoadBalancer\Strategies\ActivePassiveStrategy;
use Isg\LoadBalancer\Strategies\LeastRedirectsStrategy;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;

class StrategiesTest extends TestCase
{
    public function test_random_strategy_returns_only_active_node()
    {
        $node1 = new UpstreamNode();
        $node1->id = 1;
        $node1->is_active = false;
        
        $node2 = new UpstreamNode();
        $node2->id = 2;
        $node2->is_active = true;

        $nodes = collect([$node1, $node2]);

        $strategy = new RandomStrategy();
        $selected = $strategy->selectNode($nodes);

        $this->assertNotNull($selected);
        $this->assertEquals(2, $selected->id);
    }

    public function test_round_robin_cycles_through_active_nodes()
    {
        $node1 = new UpstreamNode();
        $node1->id = 1;
        $node1->is_active = true;
        
        $node2 = new UpstreamNode();
        $node2->id = 2;
        $node2->is_active = true;

        $nodes = collect([$node1, $node2]);

        $strategy = new RoundRobinStrategy();
        
        $first = $strategy->selectNode($nodes);
        $second = $strategy->selectNode($nodes);
        
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotEquals($first->id, $second->id);
    }

    public function test_weighted_random_respects_weights()
    {
        $node1 = new UpstreamNode();
        $node1->id = 1;
        $node1->is_active = true;
        $node1->weight = 100;
        
        $node2 = new UpstreamNode();
        $node2->id = 2;
        $node2->is_active = true;
        $node2->weight = 0; // Effectively never selectable mathematically unless fallback mapping hits

        $nodes = collect([$node1, $node2]);

        $strategy = new WeightedRandomStrategy();
        $selected = $strategy->selectNode($nodes);

        // Given standard mathematical spreads, Node 1 should always be selected if Node 2 is 0 or vastly smaller
        $this->assertEquals(1, $selected->id);
    }

    public function test_active_passive_prioritizes_primary_node()
    {
        $node1 = new UpstreamNode();
        $node1->id = 1;
        $node1->is_active = true;
        $node1->is_primary = false;
        
        $node2 = new UpstreamNode();
        $node2->id = 2;
        $node2->is_active = true;
        $node2->is_primary = true;

        $nodes = collect([$node1, $node2]);

        $strategy = new ActivePassiveStrategy();
        $selected = $strategy->selectNode($nodes);

        $this->assertNotNull($selected);
        $this->assertEquals(2, $selected->id);
    }

    public function test_least_redirects_strategy_sorts_correctly()
    {
        Cache::put('node_1_redirects_load', 50);
        Cache::put('node_2_redirects_load', 10);

        $node1 = new UpstreamNode();
        $node1->id = 1;
        $node1->is_active = true;
        
        $node2 = new UpstreamNode();
        $node2->id = 2;
        $node2->is_active = true;

        $nodes = collect([$node1, $node2]);

        $strategy = new LeastRedirectsStrategy();
        $selected = $strategy->selectNode($nodes);

        // Node 2 has smaller load cache (10 < 50)
        $this->assertNotNull($selected);
        $this->assertEquals(2, $selected->id);
    }
}
