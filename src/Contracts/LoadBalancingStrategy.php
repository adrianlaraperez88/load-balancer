<?php

namespace Isg\LoadBalancer\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Models\UpstreamNode;

interface LoadBalancingStrategy
{
    /**
     * Select an upstream node from the provided collection.
     *
     * @param Collection<int, UpstreamNode> $nodes
     * @param Request|null $request
     * @return UpstreamNode|null
     */
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode;
}
