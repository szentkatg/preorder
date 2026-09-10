<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ColorImage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ColorImagePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ColorImage');
    }

    public function view(AuthUser $authUser, ColorImage $colorImage): bool
    {
        return $authUser->can('View:ColorImage');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ColorImage');
    }

    public function update(AuthUser $authUser, ColorImage $colorImage): bool
    {
        return $authUser->can('Update:ColorImage');
    }

    public function delete(AuthUser $authUser, ColorImage $colorImage): bool
    {
        return $authUser->can('Delete:ColorImage');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ColorImage');
    }
}
