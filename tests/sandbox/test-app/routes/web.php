<?php
use Illuminate\Support\Facades\Route;
use Isg\LoadBalancer\Http\Controllers\ProxyController;
Route::any('{any}', [ProxyController::class, 'handle'])->where('any', '.*');
