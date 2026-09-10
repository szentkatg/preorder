<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Color;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ColorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Color');
    }

    public function view(AuthUser $authUser, Color $color): bool
    {
        return $authUser->can('View:Color');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Color');
    }

    public function update(AuthUser $authUser, Color $color): bool
    {
        return $authUser->can('Update:Color');
    }

    public function delete(AuthUser $authUser, Color $color): bool
    {
        return $authUser->can('Delete:Color');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Color');
    }
}
