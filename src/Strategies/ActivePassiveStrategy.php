<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;

class ActivePassiveStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $activeNodes = $nodes->where('is_active', true);
        
        if ($activeNodes->isEmpty()) {
            return null;
        }

        // Try primary node first
        $primary = $activeNodes->where('is_primary', true)->first();
        if ($primary) {
            return $primary;
        }

        // Fallback to first available secondary node (not primary)
        return $activeNodes->where('is_primary', false)->first() ?? $activeNodes->first();
    }
}
