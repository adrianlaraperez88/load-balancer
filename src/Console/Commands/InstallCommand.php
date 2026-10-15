<?php

namespace Isg\LoadBalancer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\User;
use Isg\LoadBalancer\Models\UpstreamNode;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'loadbalancer:install 
                            {--force : Overwrite existing configuration}
                            {--no-migrate : Skip running database migrations}';

    /**
     * The console command description.
     */
    protected $description = 'Turnkey setup wizard for ISG Enterprise Load Balancer & Gateway';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->output->writeln('');
        $this->info('====================================================================');
        $this->info('   ISG ENTERPRISE LOAD BALANCER & GATEWAY INSTALLER                ');
        $this->info('   Laravel 12/13 & PHP 8.3 Turnkey Setup Wizard                    ');
        $this->info('====================================================================');
        $this->output->writeln('');

        // Step 1: Publish Configuration
        $this->line('<fg=cyan>Step 1:</> Publishing load-balancer configuration...');
        $this->call('vendor:publish', [
            '--provider' => 'Isg\LoadBalancer\LoadBalancerServiceProvider',
            '--tag' => 'config',
            '--force' => $this->option('force'),
        ]);

        // Step 2: Ensure HMAC Secret in .env
        $this->line('<fg=cyan>Step 2:</> Verifying Zero-Trust HMAC-SHA256 signature key...');
        $this->ensureHmacSecret();

        // Step 3: Database Migrations
        if (!$this->option('no-migrate')) {
            $this->line('<fg=cyan>Step 3:</> Running database migrations...');
            try {
                $this->call('migrate', ['--force' => true]);
            } catch (\Throwable $e) {
                $this->warn('Could not run automated migrations: ' . $e->getMessage());
                $this->line('For corporate SQL Server / DBA environments, execute the scripts in:');
                $this->line('  database/mssql/01_create_tables.sql');
                $this->line('  database/mssql/04_create_sanctum_tables.sql');
            }
        }

        // Step 4: Seed Initial Cluster Nodes if empty
        $this->line('<fg=cyan>Step 4:</> Checking upstream cluster nodes...');
        $this->seedInitialCluster();

        // Step 5: Provision Sanctum Admin Token
        $this->line('<fg=cyan>Step 5:</> Provisioning API Administrator & Sanctum Token...');
        $token = $this->provisionAdminSanctumUser();

        $this->output->writeln('');
        $this->info('====================================================================');
        $this->info('   INSTALLATION COMPLETE! CLUSTER IS READY                         ');
        $this->info('====================================================================');
        $this->output->writeln('');
        $this->line('  <fg=green>✔</> <options=bold>Interactive Swagger Docs:</> http://localhost:8080/api/docs');
        $this->line('  <fg=green>✔</> <options=bold>Cluster Web Admin GUI:</>   http://localhost:8080/admin/cluster');
        $this->line('  <fg=green>✔</> <options=bold>OpenAPI 3.0 JSON Spec:</>   http://localhost:8080/api/docs/openapi.json');
        if ($token) {
            $this->line("  <fg=green>✔</> <options=bold>Sanctum Admin Token:</>      {$token}");
        }
        $this->output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Ensure a secure 64-char HMAC secret exists in .env.
     */
    protected function ensureHmacSecret(): void
    {
        $envPath = base_path('.env');
        if (!File::exists($envPath)) {
            $this->warn('.env file not found, skipping secret injection.');
            return;
        }

        $envContent = File::get($envPath);
        if (str_contains($envContent, 'LOAD_BALANCER_SIGNATURE_SECRET=') && !str_contains($envContent, 'LOAD_BALANCER_SIGNATURE_SECRET=' . "\n")) {
            $this->info('  ✔ Existing HMAC secret detected.');
            return;
        }

        $newSecret = bin2hex(random_bytes(32));
        if (str_contains($envContent, 'LOAD_BALANCER_SIGNATURE_SECRET=')) {
            $envContent = preg_replace(
                '/^LOAD_BALANCER_SIGNATURE_SECRET=.*$/m',
                "LOAD_BALANCER_SIGNATURE_SECRET={$newSecret}",
                $envContent
            );
        } else {
            $envContent .= "\n# Zero-Trust HMAC Signing\nLOAD_BALANCER_SIGNATURE_ENABLED=true\nLOAD_BALANCER_SIGNATURE_SECRET={$newSecret}\nLOAD_BALANCER_SIGNATURE_TOLERANCE=300\n";
        }

        File::put($envPath, $envContent);
        $this->info("  ✔ Generated new 64-char HMAC secret: {$newSecret}");
    }

    /**
     * Seed initial node if no nodes exist.
     */
    protected function seedInitialCluster(): void
    {
        try {
            if (UpstreamNode::count() === 0) {
                UpstreamNode::create([
                    'name' => 'backend_app01',
                    'url' => 'http://127.0.0.1:8001',
                    'weight' => 1,
                    'is_active' => true,
                    'is_primary' => false,
                ]);
                $this->info('  ✔ Seeded default upstream node: backend_app01 -> http://127.0.0.1:8001');
            } else {
                $count = UpstreamNode::count();
                $this->info("  ✔ {$count} upstream nodes already configured in cluster database.");
            }
        } catch (\Throwable $e) {
            $this->warn('  Could not query upstream_nodes table: ' . $e->getMessage());
        }
    }

    /**
     * Provision or locate default admin user and issue a token.
     */
    protected function provisionAdminSanctumUser(): ?string
    {
        try {
            if (!class_exists(User::class)) {
                return null;
            }

            $user = User::firstOrCreate(
                ['email' => 'admin@enterprise.com'],
                [
                    'name' => 'Cluster Administrator',
                    'password' => bcrypt('AdminSecret123!'),
                ]
            );

            if (method_exists($user, 'createToken')) {
                // Delete previous tokens for cleanliness
                $user->tokens()->where('name', 'cluster-admin')->delete();
                $token = $user->createToken('cluster-admin')->plainTextToken;
                $this->info('  ✔ Generated Sanctum API token for admin@enterprise.com');
                return $token;
            }
        } catch (\Throwable $e) {
            $this->warn('  Could not provision Sanctum token: ' . $e->getMessage());
        }

        return null;
    }
}
