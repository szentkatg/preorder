<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Partner;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PartnerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Partner');
    }

    public function view(AuthUser $authUser, Partner $partner): bool
    {
        return $authUser->can('View:Partner');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Partner');
    }

    public function update(AuthUser $authUser, Partner $partner): bool
    {
        return $authUser->can('Update:Partner');
    }

    public function delete(AuthUser $authUser, Partner $partner): bool
    {
        return $authUser->can('Delete:Partner');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Partner');
    }
}
