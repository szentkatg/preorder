<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageRoles($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->canManageRoles($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageRoles($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $role->name !== config('filament-shield.super_admin.name', 'super_admin')
            && $this->canManageRoles($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return $role->name !== config('filament-shield.super_admin.name', 'super_admin')
            && $this->canManageRoles($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canManageRoles(User $user): bool
    {
        return $user->hasRole(
            (string) config('filament-shield.super_admin.name', 'super_admin'),
        );
    }
}
