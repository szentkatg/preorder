<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Season;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SeasonPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Season');
    }

    public function view(AuthUser $authUser, Season $season): bool
    {
        return $authUser->can('View:Season');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Season');
    }

    public function update(AuthUser $authUser, Season $season): bool
    {
        return $authUser->can('Update:Season');
    }

    public function delete(AuthUser $authUser, Season $season): bool
    {
        return $authUser->can('Delete:Season');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Season');
    }
}
