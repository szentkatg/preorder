<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ItemAssortment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ItemAssortmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ItemAssortment');
    }

    public function view(AuthUser $authUser, ItemAssortment $itemAssortment): bool
    {
        return $authUser->can('View:ItemAssortment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ItemAssortment');
    }

    public function update(AuthUser $authUser, ItemAssortment $itemAssortment): bool
    {
        return $authUser->can('Update:ItemAssortment');
    }

    public function delete(AuthUser $authUser, ItemAssortment $itemAssortment): bool
    {
        return $authUser->can('Delete:ItemAssortment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ItemAssortment');
    }
}
