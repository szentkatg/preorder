<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PricingProject;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PricingProjectPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PricingProject');
    }

    public function view(AuthUser $authUser, PricingProject $pricingProject): bool
    {
        return $authUser->can('View:PricingProject');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PricingProject');
    }

    public function update(AuthUser $authUser, PricingProject $pricingProject): bool
    {
        return $authUser->can('Update:PricingProject');
    }

    public function delete(AuthUser $authUser, PricingProject $pricingProject): bool
    {
        return $authUser->can('Delete:PricingProject');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PricingProject');
    }
}
