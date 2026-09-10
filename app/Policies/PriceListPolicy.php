<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceList;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PriceListPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PriceList');
    }

    public function view(AuthUser $authUser, PriceList $priceList): bool
    {
        return $authUser->can('View:PriceList');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PriceList');
    }

    public function update(AuthUser $authUser, PriceList $priceList): bool
    {
        return $authUser->can('Update:PriceList');
    }

    public function delete(AuthUser $authUser, PriceList $priceList): bool
    {
        return $authUser->can('Delete:PriceList');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PriceList');
    }
}
