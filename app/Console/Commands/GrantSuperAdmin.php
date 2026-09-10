<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GrantSuperAdmin extends Command
{
    protected $signature = 'admin:grant-super-admin {email : The admin user email address}';

    protected $description = 'Assign the super_admin role to an existing admin user';

    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user) {
            $this->components->error("Admin user not found: {$email}");

            return self::FAILURE;
        }

        $role = Role::findOrCreate(
            (string) config('filament-shield.super_admin.name', 'super_admin'),
            'web',
        );

        $user->assignRole($role);
        $permissionRegistrar->forgetCachedPermissions();

        $this->components->info("Super admin role assigned to {$user->email}.");

        return self::SUCCESS;
    }
}
