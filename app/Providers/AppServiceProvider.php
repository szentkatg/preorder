<?php

namespace App\Providers;

use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentShield::prohibitDestructiveCommands($this->app->isProduction());

        Gate::before(function (mixed $user, string $ability, array $arguments): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            static $rolesTableExists;

            $rolesTableExists ??= Schema::hasTable(
                (string) config('permission.table_names.roles', 'roles'),
            );

            if (! $rolesTableExists) {
                return null;
            }

            $superAdminRole = (string) config(
                'filament-shield.super_admin.name',
                'super_admin',
            );

            if (! $user->hasRole($superAdminRole)) {
                return null;
            }

            $authorizationTarget = $arguments[0] ?? null;

            if ($authorizationTarget instanceof Role
                || $authorizationTarget === Role::class
                || $authorizationTarget instanceof User
                || $authorizationTarget === User::class) {
                return null;
            }

            return true;
        });
    }
}
