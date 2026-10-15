<?php

namespace Isg\LoadBalancer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Isg\LoadBalancer\Models\UpstreamNode;

class HealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loadbalancer:health-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Proactively ping all upstream nodes concurrently via Http::pool and update active status.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Initializing Enterprise Health Check Daemon...');

        $nodes = UpstreamNode::all();

        if ($nodes->isEmpty()) {
            $this->warn('No upstream nodes configured to health check.');
            return Command::SUCCESS;
        }

        $healthPath = '/' . ltrim((string) config('load-balancer.health_check.path', '/'), '/');
        $timeout = max(1, (int) config('load-balancer.health_check.timeout', 3));
        $failThreshold = max(1, (int) config('load-balancer.health_check.consecutive_failures', 2));

        // Ping all nodes concurrently in parallel
        $responses = Http::pool(function (Pool $pool) use ($nodes, $healthPath, $timeout) {
            foreach ($nodes as $node) {
                $target = rtrim($node->url, '/') . ($healthPath === '/' ? '' : $healthPath);
                $pool->as((string) $node->id)->timeout($timeout)->get($target);
            }
        });

        foreach ($nodes as $node) {
            $id = (string) $node->id;
            $res = $responses[$id] ?? null;

            $isHealthy = ($res instanceof ClientResponse) && ($res->successful() || $res->redirect());

            if ($isHealthy) {
                Cache::forget("node_{$node->id}_health_fails");

                if (!$node->is_active) {
                    $node->update(['is_active' => true]);
                    $this->info("Node [{$node->id}] ({$node->name}) fully recovered. Marked ACTIVE.");
                } else {
                    $this->line("Node [{$node->id}] ({$node->name}) is healthy.");
                }
            } else {
                Cache::add("node_{$node->id}_health_fails", 0, 3600);
                $fails = (int) Cache::increment("node_{$node->id}_health_fails");

                if ($fails >= $failThreshold) {
                    if ($node->is_active) {
                        $node->update(['is_active' => false]);
                        $this->warn("Node [{$node->id}] ({$node->name}) reached {$fails}/{$failThreshold} failures. Marked DEACTIVATED.");
                    } else {
                        $this->line("Node [{$node->id}] ({$node->name}) remains inactive ({$fails} consecutive failures).");
                    }
                } else {
                    $this->line("Node [{$node->id}] ({$node->name}) soft failure ({$fails}/{$failThreshold}).");
                }
            }
        }

        $this->info('Health Check Sweep Completed gracefully.');
        return Command::SUCCESS;
    }
}
