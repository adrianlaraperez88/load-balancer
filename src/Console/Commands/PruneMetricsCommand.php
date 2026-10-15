<?php

namespace Isg\LoadBalancer\Console\Commands;

use Illuminate\Console\Command;
use Isg\LoadBalancer\Models\RequestMetric;

class PruneMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loadbalancer:prune-metrics 
                            {--days=7 : Number of days of metrics to retain}
                            {--chunk=5000 : Chunk size for batch deletion to prevent table locks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune historical request_metrics in batches to free disk space without locking the database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);

        $this->info("Pruning request metrics older than {$days} days (before {$cutoff->toDateTimeString()})...");

        $totalDeleted = 0;

        do {
            // Delete by chunk to prevent transaction log bloat & table locks in MSSQL / MySQL
            $ids = RequestMetric::where('created_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = RequestMetric::whereIn('id', $ids)->delete();
            $totalDeleted += $deleted;

            $this->line("Pruned batch of {$deleted} metrics (Total so far: {$totalDeleted})...");
        } while ($ids->count() === $chunkSize);

        $this->info("Prune complete! Successfully removed {$totalDeleted} historical metric records.");
        return Command::SUCCESS;
    }
}
