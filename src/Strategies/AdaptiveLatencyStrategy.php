<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;

class AdaptiveLatencyStrategy implements LoadBalancingStrategy
{
    /**
     * Select node using the Power-of-Two-Choices (P2C) algorithm over EMA latencies.
     * Picks two random candidates from the pool and selects the faster one,
     * preventing the herd-oscillation effect inherent in "always pick absolute fastest".
     */
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $activeNodes = $nodes->filter(fn ($node) => $node->is_active)->values();

        $count = $activeNodes->count();
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return $activeNodes->first();
        }

        // Pick two distinct random nodes (Power of Two Choices)
        $indices = array_rand($activeNodes->all(), 2);
        $nodeA = $activeNodes->get($indices[0]);
        $nodeB = $activeNodes->get($indices[1]);

        $latencyA = (int) Cache::get("node_{$nodeA->id}_latency", 1000);
        $latencyB = (int) Cache::get("node_{$nodeB->id}_latency", 1000);

        return $latencyA <= $latencyB ? $nodeA : $nodeB;
    }
}
