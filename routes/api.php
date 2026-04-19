<?php

use Illuminate\Support\Facades\Route;
use Isg\LoadBalancer\Http\Controllers\MetricsAPIController;

Route::prefix('api/load-balancer')->group(function () {
    Route::get('/metrics', [MetricsAPIController::class, 'index'])->name('load-balancer.api.metrics');
});
