<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PricingRoundingRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PricingRoundingRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PricingRoundingRule');
    }

    public function view(AuthUser $authUser, PricingRoundingRule $pricingRoundingRule): bool
    {
        return $authUser->can('View:PricingRoundingRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PricingRoundingRule');
    }

    public function update(AuthUser $authUser, PricingRoundingRule $pricingRoundingRule): bool
    {
        return $authUser->can('Update:PricingRoundingRule');
    }

    public function delete(AuthUser $authUser, PricingRoundingRule $pricingRoundingRule): bool
    {
        return $authUser->can('Delete:PricingRoundingRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PricingRoundingRule');
    }
}
