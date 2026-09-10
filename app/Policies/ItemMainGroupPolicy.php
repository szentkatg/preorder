<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ItemMainGroup;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ItemMainGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ItemMainGroup');
    }

    public function view(AuthUser $authUser, ItemMainGroup $itemMainGroup): bool
    {
        return $authUser->can('View:ItemMainGroup');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ItemMainGroup');
    }

    public function update(AuthUser $authUser, ItemMainGroup $itemMainGroup): bool
    {
        return $authUser->can('Update:ItemMainGroup');
    }

    public function delete(AuthUser $authUser, ItemMainGroup $itemMainGroup): bool
    {
        return $authUser->can('Delete:ItemMainGroup');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ItemMainGroup');
    }
}
