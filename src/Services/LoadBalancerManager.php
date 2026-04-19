<?php

namespace Isg\LoadBalancer\Services;

use Illuminate\Http\Request;
use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Isg\LoadBalancer\Models\IpRule;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Strategies\ActivePassiveStrategy;
use Isg\LoadBalancer\Strategies\RandomStrategy;
use Isg\LoadBalancer\Strategies\RoundRobinStrategy;
use Isg\LoadBalancer\Strategies\WeightedRandomStrategy;
use Isg\LoadBalancer\Strategies\LeastRedirectsStrategy;
use Illuminate\Support\Facades\Cache;

class LoadBalancerManager
{
    public function resolveNode(Request $request, array $excludeNodeIds = []): ?UpstreamNode
    {
        $ip = $request->ip();

        // 1. Explicit IP Rules via Cache
        $ipRules = Cache::rememberForever('isg_load_balancer_ip_rules', function () {
            return IpRule::with('upstreamNode')->get()->keyBy('ip_address');
        });

        if ($ipRules->has($ip)) {
            $ruleNode = $ipRules->get($ip)->upstreamNode;
            if ($ruleNode && $ruleNode->is_active && !in_array($ruleNode->id, $excludeNodeIds) && !$this->isNodeDown($ruleNode->id)) {
                return $ruleNode;
            }
        }

        // 2. Fetch Active Nodes from Cache
        $allActiveNodes = Cache::rememberForever('isg_load_balancer_active_nodes', function () {
            return UpstreamNode::where('is_active', true)->get();
        });

        // Filter out locally excluded nodes and globally down nodes
        $availableNodes = $allActiveNodes->filter(function ($node) use ($excludeNodeIds) {
            return !in_array($node->id, $excludeNodeIds) && !$this->isNodeDown($node->id);
        })->values();

        if ($availableNodes->isEmpty()) {
            return null;
        }

        // 3. Sticky Sessions (IP Hashing)
        if (config('load-balancer.sticky_sessions', false)) {
            $hash = abs(crc32($ip));
            $index = $hash % $availableNodes->count();
            return $availableNodes->get($index);
        }

        // 4. Delegation to default Strategy
        $strategyType = config('load-balancer.default_strategy', 'round_robin');
        $strategy = $this->getStrategyInstance($strategyType);

        return $strategy->selectNode($availableNodes, $request);
    }

    public function markNodeDown(int $nodeId): void
    {
        $downNodes = Cache::get('isg_load_balancer_down_nodes', []);
        // Suspend for 1 minute
        $downNodes[$nodeId] = now()->addMinutes(1)->timestamp;
        Cache::put('isg_load_balancer_down_nodes', $downNodes);
    }

    protected function isNodeDown(int $nodeId): bool
    {
        $downNodes = Cache::get('isg_load_balancer_down_nodes', []);
        if (isset($downNodes[$nodeId]) && $downNodes[$nodeId] > now()->timestamp) {
            return true;
        }
        return false;
    }

    public function incrementNodeLoad(int $nodeId): void
    {
        // Keep tracking for 24 hours (86400 seconds)
        // If it doesn't exist, set it to 0 first, then increment
        Cache::add('node_' . $nodeId . '_redirects_load', 0, 86400);
        Cache::increment('node_' . $nodeId . '_redirects_load');
    }

    protected function getStrategyInstance(string $type): LoadBalancingStrategy
    {
        $strategies = config('load-balancer.strategies', []);

        if (isset($strategies[$type])) {
            return app()->make($strategies[$type]);
        }

        // Default fallback if type is missing from config array
        return new \Isg\LoadBalancer\Strategies\RoundRobinStrategy();
    }
}
