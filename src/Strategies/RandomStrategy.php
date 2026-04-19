<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;

class RandomStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $activeNodes = $nodes->where('is_active', true);
        
        if ($activeNodes->isEmpty()) {
            return null;
        }

        return $activeNodes->random();
    }
}
