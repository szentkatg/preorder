<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductPurchasePrice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductPurchasePricePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductPurchasePrice');
    }

    public function view(AuthUser $authUser, ProductPurchasePrice $productPurchasePrice): bool
    {
        return $authUser->can('View:ProductPurchasePrice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductPurchasePrice');
    }

    public function update(AuthUser $authUser, ProductPurchasePrice $productPurchasePrice): bool
    {
        return $authUser->can('Update:ProductPurchasePrice');
    }

    public function delete(AuthUser $authUser, ProductPurchasePrice $productPurchasePrice): bool
    {
        return $authUser->can('Delete:ProductPurchasePrice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductPurchasePrice');
    }
}
