#!/bin/sh

# setup-test-app.sh
# Runs inside the Docker container to bootstrap the sandbox test app.

APP_DIR="/var/www/html/tests/sandbox/test-app"

if [ ! -d "$APP_DIR" ]; then
    echo "Creating Laravel dummy application for Sandbox verification..."
    composer create-project laravel/laravel "$APP_DIR" --prefer-dist -q
    
    cd "$APP_DIR"
    
    echo "Linking Load Balancer Package natively..."
    composer config repositories.local '{"type": "path", "url": "../../../"}'
    composer require adrianlaraperez88/load-balancer @dev
    
    echo "Publishing Configurations and Migrating Databases..."
    php artisan vendor:publish --provider="Isg\LoadBalancer\LoadBalancerServiceProvider"
    php artisan migrate --force
    
    echo "Registering upstream Sandbox Docker Nodes..."
    php artisan tinker --execute="Isg\LoadBalancer\Models\UpstreamNode::create(['name'=>'Node 1','url'=>'http://node1','is_active'=>true,'weight'=>1]); Isg\LoadBalancer\Models\UpstreamNode::create(['name'=>'Node 2','url'=>'http://node2','is_active'=>true,'weight'=>1]); Isg\LoadBalancer\Models\UpstreamNode::create(['name'=>'Node 3','url'=>'http://node3','is_active'=>true,'weight'=>1]);"
    
    echo "Rewriting default routing..."
    echo "<?php" > routes/web.php
    echo "use Illuminate\Support\Facades\Route;" >> routes/web.php
    echo "use Isg\LoadBalancer\Http\Controllers\ProxyController;" >> routes/web.php
    echo "Route::any('{any}', [ProxyController::class, 'handle'])->where('any', '.*');" >> routes/web.php
    
    echo "Dummy Application fully prepared!"
else
    echo "Dummy Application exists, skipping recreation..."
fi

cd "$APP_DIR"
exec php artisan serve --host=0.0.0.0 --port=8000
