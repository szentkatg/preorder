<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GrantSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_the_super_admin_role_to_an_existing_admin_user(): void
    {
        $user = User::factory()->create([
            'email' => 'szega77@hotmail.com',
        ]);

        $this->artisan('admin:grant-super-admin', [
            'email' => 'SZEGA77@HOTMAIL.COM',
        ])
            ->expectsOutputToContain('Super admin role assigned')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->hasRole('super_admin'));
    }

    public function test_the_bootstrap_migration_assigns_the_role_to_the_initial_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'szega77@hotmail.com',
        ]);

        $migration = require database_path('migrations/2026_09_10_070000_assign_initial_super_admin_role.php');
        $migration->up();

        $this->assertTrue($user->refresh()->hasRole('super_admin'));
    }

    public function test_it_can_be_run_repeatedly_without_creating_duplicate_roles(): void
    {
        User::factory()->create([
            'email' => 'szega77@hotmail.com',
        ]);

        $this->artisan('admin:grant-super-admin', [
            'email' => 'szega77@hotmail.com',
        ])->assertSuccessful();

        $this->artisan('admin:grant-super-admin', [
            'email' => 'szega77@hotmail.com',
        ])->assertSuccessful();

        $this->assertSame(1, Role::query()->where('name', 'super_admin')->count());
    }

    public function test_it_fails_when_the_admin_user_does_not_exist(): void
    {
        $this->artisan('admin:grant-super-admin', [
            'email' => 'missing@example.com',
        ])
            ->expectsOutputToContain('Admin user not found')
            ->assertFailed();
    }

    public function test_the_development_seed_user_receives_the_super_admin_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(
            User::query()->where('email', 'test@example.com')->firstOrFail()->hasRole('super_admin'),
        );
    }
}
