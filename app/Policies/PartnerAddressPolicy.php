<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PartnerAddress;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PartnerAddressPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PartnerAddress');
    }

    public function view(AuthUser $authUser, PartnerAddress $partnerAddress): bool
    {
        return $authUser->can('View:PartnerAddress');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PartnerAddress');
    }

    public function update(AuthUser $authUser, PartnerAddress $partnerAddress): bool
    {
        return $authUser->can('Update:PartnerAddress');
    }

    public function delete(AuthUser $authUser, PartnerAddress $partnerAddress): bool
    {
        return $authUser->can('Delete:PartnerAddress');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PartnerAddress');
    }
}
