<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Gateway Ingress)
|--------------------------------------------------------------------------
|
| The load balancer catches all incoming web traffic on app03 and
| transparently reverse-proxies payloads to healthy backend nodes.
|
*/

// ==============================================================================
// OpenAPI & Swagger UI Interactive Documentation
// ==============================================================================
Route::get('/api/docs', function () {
    return response()->file(public_path('docs/index.html'));
})->name('api.docs');

Route::get('/api/docs/openapi.json', function () {
    return response()->file(public_path('openapi.json'), [
        'Content-Type' => 'application/json',
    ]);
})->name('api.docs.spec');

Route::loadBalancer('{any?}');
