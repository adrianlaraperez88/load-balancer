<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InstallCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LoadBalancerServiceProvider::class,
        ];
    }

    public function test_install_command_executes_successfully()
    {
        $this->artisan('loadbalancer:install', ['--no-migrate' => true, '--force' => true])
            ->expectsOutputToContain('ISG ENTERPRISE LOAD BALANCER & GATEWAY INSTALLER')
            ->expectsOutputToContain('INSTALLATION COMPLETE! CLUSTER IS READY')
            ->expectsOutputToContain('Interactive Swagger Docs')
            ->assertExitCode(0);
    }
}
