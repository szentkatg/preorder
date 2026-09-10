<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceListItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PriceListItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PriceListItem');
    }

    public function view(AuthUser $authUser, PriceListItem $priceListItem): bool
    {
        return $authUser->can('View:PriceListItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PriceListItem');
    }

    public function update(AuthUser $authUser, PriceListItem $priceListItem): bool
    {
        return $authUser->can('Update:PriceListItem');
    }

    public function delete(AuthUser $authUser, PriceListItem $priceListItem): bool
    {
        return $authUser->can('Delete:PriceListItem');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PriceListItem');
    }
}
