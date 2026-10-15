<?php

namespace Isg\LoadBalancer;

use Illuminate\Support\ServiceProvider;

class LoadBalancerServiceProvider extends ServiceProvider
{
    /**
     * Middleware that should not run on proxied routes when mounted in stateful groups.
     */
    public const STATEFUL_MIDDLEWARE = [
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ];

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

        // Catch-all proxy route. It must never run app03's own session, CSRF or
        // cookie-encryption middleware: those would rewrite/encrypt backend
        // cookies, start a competing session and reject API calls with 419.
        // Registered as a fallback so app03's own routes always match first.
        \Illuminate\Support\Facades\Route::macro('loadBalancer', function (string $uri = '{any?}') {
            return \Illuminate\Support\Facades\Route::any($uri, [\Isg\LoadBalancer\Http\Controllers\ProxyController::class, 'handle'])
                ->where('any', '.*')
                ->withoutMiddleware(\Isg\LoadBalancer\LoadBalancerServiceProvider::STATEFUL_MIDDLEWARE)
                ->fallback();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Isg\LoadBalancer\Console\Commands\HealthCheckCommand::class,
                \Isg\LoadBalancer\Console\Commands\ManageNodeCommand::class,
                \Isg\LoadBalancer\Console\Commands\PruneMetricsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../database/migrations/create_upstream_nodes_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_upstream_nodes_table.php'),
                __DIR__ . '/../database/migrations/create_ip_rules_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_ip_rules_table.php'),
                __DIR__ . '/../database/migrations/create_request_metrics_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_create_request_metrics_table.php'),
            ], 'migrations');
        }
    }
}
