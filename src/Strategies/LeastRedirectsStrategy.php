<?php

namespace Isg\LoadBalancer\Strategies;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Support\Facades\Cache;

class LeastRedirectsStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        if ($nodes->isEmpty()) {
            return null;
        }

        // Sort nodes by their load counter in cache (ascending)
        $sortedNodes = $nodes->sortBy(function ($node) {
            return (int) Cache::get('node_' . $node->id . '_redirects_load', 0);
        });

        // The first node has the least load
        return $sortedNodes->first();
    }
}
