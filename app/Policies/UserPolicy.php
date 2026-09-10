<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageAdminUsers($user);
    }

    public function view(User $user, User $adminUser): bool
    {
        return $this->canManageAdminUsers($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageAdminUsers($user);
    }

    public function update(User $user, User $adminUser): bool
    {
        return $this->canManageAdminUsers($user);
    }

    public function delete(User $user, User $adminUser): bool
    {
        if ($user->is($adminUser)) {
            return false;
        }

        return $this->canManageAdminUsers($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canManageAdminUsers(User $user): bool
    {
        return $user->hasRole(
            (string) config('filament-shield.super_admin.name', 'super_admin'),
        );
    }
}
