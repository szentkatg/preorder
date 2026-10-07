<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PricingParameter;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PricingParameterPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PricingParameter');
    }

    public function view(AuthUser $authUser, PricingParameter $pricingParameter): bool
    {
        return $authUser->can('View:PricingParameter');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PricingParameter');
    }

    public function update(AuthUser $authUser, PricingParameter $pricingParameter): bool
    {
        return $authUser->can('Update:PricingParameter');
    }

    public function delete(AuthUser $authUser, PricingParameter $pricingParameter): bool
    {
        return $authUser->can('Delete:PricingParameter');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PricingParameter');
    }
}
