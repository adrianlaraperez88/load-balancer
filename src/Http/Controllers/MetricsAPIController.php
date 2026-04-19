<?php

namespace Isg\LoadBalancer\Http\Controllers;

use Illuminate\Routing\Controller;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Models\RequestMetric;
use Illuminate\Support\Facades\Cache;

class MetricsAPIController extends Controller
{
    public function index()
    {
        $nodes = UpstreamNode::all()->map(function ($node) {
            return [
                'id' => $node->id,
                'name' => $node->name,
                'url' => $node->url,
                'is_active' => (bool)$node->is_active,
                'weight' => $node->weight,
                'current_latency_ms' => Cache::get("node_{$node->id}_latency", 0),
                'total_redirects_24h' => Cache::get("node_{$node->id}_redirects_load", 0),
            ];
        });

        $totalRequests = RequestMetric::count();
        
        return response()->json([
            'status' => 'success',
            'cluster_health' => [
                'total_nodes' => $nodes->count(),
                'active_nodes' => $nodes->where('is_active', true)->count(),
            ],
            'global_metrics' => [
                'total_recorded_requests' => $totalRequests,
            ],
            'nodes' => $nodes->values(),
        ]);
    }
}
