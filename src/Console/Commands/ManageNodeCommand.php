<?php

namespace Isg\LoadBalancer\Console\Commands;

use Illuminate\Console\Command;
use Isg\LoadBalancer\Models\UpstreamNode;

class ManageNodeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loadbalancer:node 
                            {action=list : Action to perform (list, add, remove, toggle)}
                            {--id= : Node ID for remove or toggle actions}
                            {--name= : Name of the upstream node (e.g. app01)}
                            {--url= : Base URL of the upstream node (e.g. http://10.0.0.11:8000)}
                            {--weight=1 : Traffic distribution weight}
                            {--primary : Flag node as primary for active-passive strategy}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage cluster upstream backend nodes for the load balancer.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = strtolower($this->argument('action'));

        return match ($action) {
            'add' => $this->addNode(),
            'remove' => $this->removeNode(),
            'toggle' => $this->toggleNode(),
            default => $this->listNodes(),
        };
    }

    protected function listNodes(): int
    {
        $nodes = UpstreamNode::all(['id', 'name', 'url', 'weight', 'is_active', 'is_primary', 'created_at']);

        if ($nodes->isEmpty()) {
            $this->warn('No upstream nodes registered in the cluster.');
            $this->line('Add nodes using: php artisan loadbalancer:node add --name=app01 --url=http://10.0.0.11:8000');
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'URL', 'Weight', 'Active', 'Primary', 'Created At'],
            $nodes->map(fn ($node) => [
                $node->id,
                $node->name,
                $node->url,
                $node->weight,
                $node->is_active ? 'YES' : 'NO',
                $node->is_primary ? 'YES' : 'NO',
                $node->created_at,
            ])
        );

        return Command::SUCCESS;
    }

    protected function addNode(): int
    {
        $name = $this->option('name');
        $url = $this->option('url');
        $weight = (int) $this->option('weight');
        $isPrimary = (bool) $this->option('primary');

        if (!$name || !$url) {
            $this->error('Both --name and --url are required when adding a node.');
            return Command::FAILURE;
        }

        $node = UpstreamNode::create([
            'name' => $name,
            'url' => rtrim($url, '/'),
            'weight' => $weight,
            'is_active' => true,
            'is_primary' => $isPrimary,
        ]);

        $this->info("Successfully registered upstream node [ID: {$node->id}] '{$node->name}' -> {$node->url}");
        return Command::SUCCESS;
    }

    protected function removeNode(): int
    {
        $id = $this->option('id');
        if (!$id) {
            $this->error('--id is required when removing a node.');
            return Command::FAILURE;
        }

        $node = UpstreamNode::find($id);
        if (!$node) {
            $this->error("Node with ID {$id} not found.");
            return Command::FAILURE;
        }

        $node->delete();
        $this->info("Successfully removed upstream node [ID: {$id}].");
        return Command::SUCCESS;
    }

    protected function toggleNode(): int
    {
        $id = $this->option('id');
        if (!$id) {
            $this->error('--id is required when toggling a node.');
            return Command::FAILURE;
        }

        $node = UpstreamNode::find($id);
        if (!$node) {
            $this->error("Node with ID {$id} not found.");
            return Command::FAILURE;
        }

        $node->update(['is_active' => !$node->is_active]);
        $status = $node->is_active ? 'ACTIVE' : 'INACTIVE';
        $this->info("Node [ID: {$id}] status updated to: {$status}");
        return Command::SUCCESS;
    }
}
