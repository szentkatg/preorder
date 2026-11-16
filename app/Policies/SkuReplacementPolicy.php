<?php

namespace App\Policies;

use App\Models\SkuReplacement;
use App\Models\User;

class SkuReplacementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:SkuReplacement');
    }

    public function view(User $user, SkuReplacement $replacement): bool
    {
        return $user->can('View:SkuReplacement');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:SkuReplacement');
    }

    public function update(User $user, SkuReplacement $replacement): bool
    {
        return $user->can('Update:SkuReplacement');
    }

    public function delete(User $user, SkuReplacement $replacement): bool
    {
        return $user->can('Delete:SkuReplacement');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:SkuReplacement');
    }
}
