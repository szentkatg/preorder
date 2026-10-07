<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PricingCalculationError;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PricingCalculationErrorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PricingCalculationError');
    }

    public function view(AuthUser $authUser, PricingCalculationError $pricingCalculationError): bool
    {
        return $authUser->can('View:PricingCalculationError');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PricingCalculationError');
    }

    public function update(AuthUser $authUser, PricingCalculationError $pricingCalculationError): bool
    {
        return $authUser->can('Update:PricingCalculationError');
    }

    public function delete(AuthUser $authUser, PricingCalculationError $pricingCalculationError): bool
    {
        return $authUser->can('Delete:PricingCalculationError');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PricingCalculationError');
    }
}
