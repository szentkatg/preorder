<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PartnerUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PartnerUser');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:PartnerUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PartnerUser');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:PartnerUser');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:PartnerUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PartnerUser');
    }
}
