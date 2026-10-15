<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use Isg\LoadBalancer\Http\Controllers\MetricsAPIController;
use Isg\LoadBalancer\Http\Controllers\NodeManagementAPIController;
use Isg\LoadBalancer\Http\Middleware\ProtectMetricsEndpoint;

// ==============================================================================
// 1. Cluster Administrative & Observability API
// Guarded by ProtectMetricsEndpoint (Bearer token and/or IP allowlist)
// ==============================================================================
Route::prefix('api/load-balancer')
    ->middleware(ProtectMetricsEndpoint::class)
    ->group(function () {
        // Observability Metrics
        Route::get('/metrics', [MetricsAPIController::class, 'index'])->name('load-balancer.api.metrics');

        // Upstream Cluster Nodes Management
        Route::get('/nodes', [NodeManagementAPIController::class, 'index'])->name('load-balancer.api.nodes.index');
        Route::post('/nodes', [NodeManagementAPIController::class, 'store'])->name('load-balancer.api.nodes.store');
        Route::get('/nodes/{id}', [NodeManagementAPIController::class, 'show'])->name('load-balancer.api.nodes.show');
        Route::patch('/nodes/{id}/toggle', [NodeManagementAPIController::class, 'toggle'])->name('load-balancer.api.nodes.toggle');
        Route::delete('/nodes/{id}', [NodeManagementAPIController::class, 'destroy'])->name('load-balancer.api.nodes.destroy');

        // IP Affinity Rules Management
        Route::get('/ip-rules', [NodeManagementAPIController::class, 'listIpRules'])->name('load-balancer.api.ip-rules.index');
        Route::post('/ip-rules', [NodeManagementAPIController::class, 'storeIpRule'])->name('load-balancer.api.ip-rules.store');
        Route::delete('/ip-rules/{id}', [NodeManagementAPIController::class, 'destroyIpRule'])->name('load-balancer.api.ip-rules.destroy');
    });

// ==============================================================================
// 2. Authentication & Secure Communications (Sanctum)
// ==============================================================================
Route::prefix('api/auth')->group(function () {
    // Public: Authenticate and issue Sanctum token with brute-force protection (OWASP A07)
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    // Protected by Sanctum Bearer token
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/workload', [AuthController::class, 'processWorkload'])->name('auth.workload');
    });
});
