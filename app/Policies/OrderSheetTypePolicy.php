<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderSheetType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OrderSheetTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderSheetType');
    }

    public function view(AuthUser $authUser, OrderSheetType $orderSheetType): bool
    {
        return $authUser->can('View:OrderSheetType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderSheetType');
    }

    public function update(AuthUser $authUser, OrderSheetType $orderSheetType): bool
    {
        return $authUser->can('Update:OrderSheetType');
    }

    public function delete(AuthUser $authUser, OrderSheetType $orderSheetType): bool
    {
        return $authUser->can('Delete:OrderSheetType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderSheetType');
    }
}
