<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SizeRange;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SizeRangePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SizeRange');
    }

    public function view(AuthUser $authUser, SizeRange $sizeRange): bool
    {
        return $authUser->can('View:SizeRange');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SizeRange');
    }

    public function update(AuthUser $authUser, SizeRange $sizeRange): bool
    {
        return $authUser->can('Update:SizeRange');
    }

    public function delete(AuthUser $authUser, SizeRange $sizeRange): bool
    {
        return $authUser->can('Delete:SizeRange');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SizeRange');
    }
}
