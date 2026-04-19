<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;

class WeightedRandomStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $activeNodes = $nodes->where('is_active', true);

        if ($activeNodes->isEmpty()) {
            return null;
        }

        $totalWeight = $activeNodes->sum('weight');
        
        if ($totalWeight <= 0) {
            return $activeNodes->random();
        }

        $rand = mt_rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($activeNodes as $node) {
            $currentWeight += $node->weight;
            if ($rand <= $currentWeight) {
                return $node;
            }
        }

        return $activeNodes->last(); // Fallback
    }
}
