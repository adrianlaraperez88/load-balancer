<?php

namespace Isg\LoadBalancer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Isg\LoadBalancer\Models\UpstreamNode;
use Isg\LoadBalancer\Models\IpRule;

class NodeManagementAPIController extends Controller
{
    /**
     * List all registered upstream nodes.
     */
    public function index(): JsonResponse
    {
        $nodes = UpstreamNode::query()
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'count' => $nodes->count(),
            'nodes' => $nodes,
        ]);
    }

    /**
     * Register a new upstream node in the database.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'url' => [
                'required',
                'string',
                'url',
                function ($attribute, $value, $fail) {
                    $parsed = parse_url($value);
                    if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
                        $fail("The {$attribute} must have an http:// or https:// scheme.");
                    }
                    if (isset($parsed['user']) || isset($parsed['pass'])) {
                        $fail("The {$attribute} must not contain embedded user credentials.");
                    }
                },
            ],
            'weight' => 'sometimes|integer|min:1|max:1000',
            'is_active' => 'sometimes|boolean',
            'is_primary' => 'sometimes|boolean',
        ]);

        $node = UpstreamNode::create([
            'name' => $validated['name'],
            'url' => rtrim($validated['url'], '/'),
            'weight' => $validated['weight'] ?? 1,
            'is_active' => $validated['is_active'] ?? true,
            'is_primary' => $validated['is_primary'] ?? false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Upstream node [ID: {$node->id}] '{$node->name}' successfully created.",
            'node' => $node,
        ], 201);
    }

    /**
     * Get a specific upstream node.
     */
    public function show(int $id): JsonResponse
    {
        $node = UpstreamNode::find($id);

        if (!$node) {
            return response()->json(['status' => 'error', 'message' => "Node [ID: {$id}] not found."], 404);
        }

        return response()->json([
            'status' => 'success',
            'node' => $node,
        ]);
    }

    /**
     * Toggle a node's active status.
     */
    public function toggle(int $id): JsonResponse
    {
        $node = UpstreamNode::find($id);

        if (!$node) {
            return response()->json(['status' => 'error', 'message' => "Node [ID: {$id}] not found."], 404);
        }

        $node->is_active = !$node->is_active;
        $node->save();

        return response()->json([
            'status' => 'success',
            'message' => "Node [ID: {$node->id}] '{$node->name}' is now " . ($node->is_active ? 'ACTIVE' : 'INACTIVE') . '.',
            'node' => $node,
        ]);
    }

    /**
     * Remove a node from the cluster.
     */
    public function destroy(int $id): JsonResponse
    {
        $node = UpstreamNode::find($id);

        if (!$node) {
            return response()->json(['status' => 'error', 'message' => "Node [ID: {$id}] not found."], 404);
        }

        $name = $node->name;
        $node->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Node [ID: {$id}] '{$name}' successfully removed.",
        ]);
    }

    /**
     * List all IP affinity rules.
     */
    public function listIpRules(): JsonResponse
    {
        $rules = IpRule::with('upstreamNode')->get();

        return response()->json([
            'status' => 'success',
            'count' => $rules->count(),
            'rules' => $rules,
        ]);
    }

    /**
     * Register a new IP affinity rule.
     */
    public function storeIpRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ip_address' => 'required|ip|unique:ip_rules,ip_address',
            'upstream_node_id' => 'required|exists:upstream_nodes,id',
        ]);

        $rule = IpRule::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => "IP affinity rule for '{$rule->ip_address}' bound to node ID {$rule->upstream_node_id}.",
            'rule' => $rule->load('upstreamNode'),
        ], 201);
    }

    /**
     * Remove an IP affinity rule.
     */
    public function destroyIpRule(int $id): JsonResponse
    {
        $rule = IpRule::find($id);

        if (!$rule) {
            return response()->json(['status' => 'error', 'message' => "IP Rule [ID: {$id}] not found."], 404);
        }

        $ip = $rule->ip_address;
        $rule->delete();

        return response()->json([
            'status' => 'success',
            'message' => "IP affinity rule for '{$ip}' removed.",
        ]);
    }
}
