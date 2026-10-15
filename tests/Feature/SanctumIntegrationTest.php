<?php

namespace Isg\LoadBalancer\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Isg\LoadBalancer\LoadBalancerServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

class SanctumIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            LoadBalancerServiceProvider::class,
            SanctumServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.sanctum', [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => User::class,
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function defineRoutes($router)
    {
        require __DIR__ . '/../../routes/api.php';
    }

    public function test_user_can_login_and_receive_sanctum_token(): string
    {
        $user = User::create([
            'name'     => 'Alice Operator',
            'email'    => 'alice@enterprise.com',
            'password' => Hash::make('SecretPass123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'alice@enterprise.com',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'alice@enterprise.com');

        $token = $response->json('access_token');
        $this->assertNotEmpty($token);

        return $token;
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name'     => 'Bob',
            'email'    => 'bob@enterprise.com',
            'password' => Hash::make('RealPassword'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'bob@enterprise.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_protected_route_rejects_unauthenticated_requests(): void
    {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    }

    public function test_protected_routes_work_with_valid_sanctum_token(): void
    {
        $user = User::create([
            'name'     => 'Charlie Admin',
            'email'    => 'charlie@enterprise.com',
            'password' => Hash::make('Password!'),
        ]);

        $token = $user->createToken('test-suite')->plainTextToken;

        // 1. Check /me
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.email', 'charlie@enterprise.com');

        // 2. Check /workload
        $workloadResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/workload', ['batch_id' => 998, 'action' => 'process']);

        $workloadResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('authenticated_user_id', $user->id)
            ->assertJsonPath('payload_processed.batch_id', 998);

        // 3. Logout (revokes token)
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // 4. Reset in-memory guard cache to simulate fresh HTTP request cycle
        $this->app['auth']->forgetGuards();

        $afterLogoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');

        $afterLogoutResponse->assertStatus(401);
    }
}
