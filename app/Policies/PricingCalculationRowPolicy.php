<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PricingCalculationRow;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PricingCalculationRowPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PricingCalculationRow');
    }

    public function view(AuthUser $authUser, PricingCalculationRow $pricingCalculationRow): bool
    {
        return $authUser->can('View:PricingCalculationRow');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PricingCalculationRow');
    }

    public function update(AuthUser $authUser, PricingCalculationRow $pricingCalculationRow): bool
    {
        return $authUser->can('Update:PricingCalculationRow');
    }

    public function delete(AuthUser $authUser, PricingCalculationRow $pricingCalculationRow): bool
    {
        return $authUser->can('Delete:PricingCalculationRow');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PricingCalculationRow');
    }
}
