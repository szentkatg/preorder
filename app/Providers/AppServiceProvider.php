<?php

namespace App\Providers;

use App\Filament\Support\AdminTableState;
use App\Http\Middleware\EnsurePartnerPortalIsAvailable;
use App\Http\Middleware\RequirePartnerSeason;
use App\Models\User;
use App\Services\Auth\LoginAuditRecorder;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
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
        Event::listen(Login::class, fn (Login $event) => app(LoginAuditRecorder::class)->record($event));
        Livewire::addPersistentMiddleware([EnsurePartnerPortalIsAvailable::class, RequirePartnerSeason::class]);
        Table::configureUsing(AdminTableState::configure(...));
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
