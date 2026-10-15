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

        // Fetch active nodes and their circuit state in one batched cache read.
        $allActiveNodes = Cache::rememberForever('isg_load_balancer_active_nodes', function () {
            return UpstreamNode::where('is_active', true)->get();
        });

        $candidates = $allActiveNodes->filter(
            fn ($node) => !in_array($node->id, $excludeNodeIds)
        )->values();

        $openCircuits = $this->openCircuits($candidates->pluck('id')->all());

        // 1. Explicit IP Rules via Cache
        $ipRules = Cache::rememberForever('isg_load_balancer_ip_rules', function () {
            return IpRule::with('upstreamNode')->get()->keyBy('ip_address');
        });

        if ($ipRules->has($ip)) {
            $ruleNode = $ipRules->get($ip)->upstreamNode;
            if ($ruleNode && $ruleNode->is_active && !in_array($ruleNode->id, $excludeNodeIds) && !isset($openCircuits[$ruleNode->id])) {
                return $ruleNode;
            }
        }

        // 2. Filter out nodes whose circuit is open
        $availableNodes = $candidates->filter(
            fn ($node) => !isset($openCircuits[$node->id])
        )->values();

        // Fail open: if every remaining node is tripped, keep serving through
        // them rather than returning 503 to every user (avoids cascading outage).
        if ($availableNodes->isEmpty()) {
            $availableNodes = $candidates;
        }

        if ($availableNodes->isEmpty()) {
            return null;
        }

        // 3. Sticky Sessions (Rendezvous / Highest Random Weight Consistent Hashing)
        if (config('load-balancer.sticky_sessions', false)) {
            $bestNode = null;
            $highestWeight = -1;

            foreach ($availableNodes as $node) {
                // Deterministic 32-bit score per IP + Node combination.
                // Guarantees minimal re-mapping (only 1/N) when nodes scale up or down.
                $score = hexdec(substr(md5($ip . '_' . $node->id), 0, 8));
                if ($score > $highestWeight) {
                    $highestWeight = $score;
                    $bestNode = $node;
                }
            }

            return $bestNode ?: $availableNodes->first();
        }

        // 4. Delegation to default Strategy
        $strategyType = config('load-balancer.default_strategy', 'round_robin');
        $strategy = $this->getStrategyInstance($strategyType);

        return $strategy->selectNode($availableNodes, $request);
    }

    /**
     * Record a failure to CONNECT to a node. Only after `failure_threshold`
     * failures inside `window_seconds` is the circuit opened for
     * `cooldown_seconds`. Read timeouts must not be reported here: a slow
     * node is not a dead node.
     */
    public function recordConnectFailure(int $nodeId): void
    {
        $threshold = max(1, (int) config('load-balancer.circuit_breaker.failure_threshold', 5));
        $window = max(1, (int) config('load-balancer.circuit_breaker.window_seconds', 30));

        $failKey = $this->failureKey($nodeId);

        // add() is a no-op if the key exists; increment() is atomic on
        // redis / memcached / database stores.
        Cache::add($failKey, 0, $window);
        $failures = (int) Cache::increment($failKey);

        if ($failures >= $threshold) {
            $this->markNodeDown($nodeId);
            Cache::forget($failKey);
        }
    }

    /**
     * Immediately open the circuit for a node for `cooldown_seconds`.
     */
    public function markNodeDown(int $nodeId): void
    {
        $cooldown = max(1, (int) config('load-balancer.circuit_breaker.cooldown_seconds', 30));
        Cache::put($this->circuitKey($nodeId), now()->addSeconds($cooldown)->timestamp, $cooldown);
    }

    public function isNodeDown(int $nodeId): bool
    {
        return isset($this->openCircuits([$nodeId])[$nodeId]);
    }

    /**
     * @param  array<int, int>  $nodeIds
     * @return array<int, true>  Map of node id => true for open circuits.
     */
    protected function openCircuits(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $keys = [];
        foreach ($nodeIds as $id) {
            $keys[$this->circuitKey($id)] = $id;
        }

        $now = now()->timestamp;
        $open = [];
        foreach (Cache::many(array_keys($keys)) as $key => $openUntil) {
            if ($openUntil !== null && (int) $openUntil > $now) {
                $open[$keys[$key]] = true;
            }
        }

        return $open;
    }

    protected function circuitKey(int $nodeId): string
    {
        return "isg_lb_cb_open_{$nodeId}";
    }

    protected function failureKey(int $nodeId): string
    {
        return "isg_lb_cb_fail_{$nodeId}";
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
