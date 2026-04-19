<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Support\Facades\Cache;

class RoundRobinStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        if ($nodes->isEmpty()) {
            return null;
        }

        $activeNodes = $nodes->where('is_active', true)->values();
        
        if ($activeNodes->isEmpty()) {
            return null;
        }

        $count = $activeNodes->count();
        $index = Cache::increment('isg_load_balancer_round_robin_index') % $count;

        return $activeNodes->get($index);
    }
}
