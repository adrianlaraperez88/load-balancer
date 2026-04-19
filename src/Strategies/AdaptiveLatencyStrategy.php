<?php

namespace Isg\LoadBalancer\Strategies;

use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Support\Facades\Cache;

class AdaptiveLatencyStrategy implements LoadBalancingStrategy
{
    /**
     * Select fundamentally fastest active node mathematically via Exponential Moving Averages.
     *
     * @param Collection $nodes
     * @param Request|null $request
     * @return UpstreamNode|null
     */
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $activeNodes = $nodes->filter(fn ($node) => $node->is_active);

        if ($activeNodes->isEmpty()) {
            return null;
        }

        // Sort ascending by cached latency (lower is faster)
        $sortedNodes = $activeNodes->sortBy(function ($node) {
            // Default penalty of 1000ms if no metrics recorded avoiding cold-start collisions
            return Cache::get("node_{$node->id}_latency", 1000); 
        });

        // Pull the fastest one organically
        return $sortedNodes->first();
    }
}
