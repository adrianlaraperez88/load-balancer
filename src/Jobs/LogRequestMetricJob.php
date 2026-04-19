<?php

namespace Isg\LoadBalancer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Isg\LoadBalancer\Models\RequestMetric;
use Illuminate\Support\Facades\Cache;

class LogRequestMetricJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $incomingIp,
        protected ?int $upstreamNodeId,
        protected string $method,
        protected string $uri,
        protected int $statusCode,
        protected int $responseTimeMs
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        RequestMetric::create([
            'incoming_ip' => $this->incomingIp,
            'upstream_node_id' => $this->upstreamNodeId,
            'method' => $this->method,
            'uri' => $this->uri,
            'status_code' => $this->statusCode,
            'response_time_ms' => $this->responseTimeMs,
        ]);

        if ($this->upstreamNodeId) {
            $cacheKey = "node_{$this->upstreamNodeId}_latency";
            $currentLatency = Cache::get($cacheKey, $this->responseTimeMs);
            
            // Exponential Moving Average mapping (90% old, 10% new)
            $newLatency = (int) (($currentLatency * 0.9) + ($this->responseTimeMs * 0.1));
            
            Cache::put($cacheKey, $newLatency);
        }
    }
}
