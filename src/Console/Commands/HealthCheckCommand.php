<?php

namespace Isg\LoadBalancer\Console\Commands;

use Illuminate\Console\Command;
use Isg\LoadBalancer\Models\UpstreamNode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Proactively ping all upstream nodes and mathematically update database statuses safely.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Initializing Enterprise Health Check Daemon...');

        $nodes = UpstreamNode::all();

        foreach ($nodes as $node) {
            try {
                // Ping the raw URL with a strict 3-second network timeout limits
                $response = Http::timeout(3)->get($node->url);

                if ($response->successful() || $response->isRedirect()) {
                    if (!$node->is_active) {
                        $node->update(['is_active' => true]);
                        $this->info("Node [{$node->id}] ({$node->name}) fully recovered. Marked ACTIVE.");
                    } else {
                        $this->line("Node [{$node->id}] ({$node->name}) is perfectly healthy.");
                    }
                } else {
                    if ($node->is_active) {
                        $node->update(['is_active' => false]);
                        $this->warn("Node [{$node->id}] ({$node->name}) returned {$response->status()}. Marked DEACTIVATED.");
                    }
                }
            } catch (\Exception $e) {
                // Total network drop or massive timeouts
                if ($node->is_active) {
                    $node->update(['is_active' => false]);
                    $this->error("Node [{$node->id}] ({$node->name}) physically dropped connection. Marked DEACTIVATED.");
                }
            }
        }

        $this->info('Health Check Sweep Completed gracefully.');
        return Command::SUCCESS;
    }
}
