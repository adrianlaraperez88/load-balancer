<?php

namespace Isg\LoadBalancer;

use Illuminate\Support\ServiceProvider;

class LoadBalancerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/load-balancer.php', 'load-balancer'
        );

        $this->app->singleton(\Isg\LoadBalancer\Services\LoadBalancerManager::class, function ($app) {
            return new \Isg\LoadBalancer\Services\LoadBalancerManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/load-balancer.php' => config_path('load-balancer.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Isg\LoadBalancer\Console\Commands\HealthCheckCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../database/migrations/create_upstream_nodes_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_upstream_nodes_table.php'),
                __DIR__ . '/../database/migrations/create_ip_rules_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_ip_rules_table.php'),
                __DIR__ . '/../database/migrations/create_request_metrics_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_create_request_metrics_table.php'),
            ], 'migrations');
        }
    }
}
