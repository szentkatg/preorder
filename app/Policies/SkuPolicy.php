<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sku;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SkuPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Sku');
    }

    public function view(AuthUser $authUser, Sku $sku): bool
    {
        return $authUser->can('View:Sku');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Sku');
    }

    public function update(AuthUser $authUser, Sku $sku): bool
    {
        return $authUser->can('Update:Sku');
    }

    public function delete(AuthUser $authUser, Sku $sku): bool
    {
        return $authUser->can('Delete:Sku');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Sku');
    }
}
