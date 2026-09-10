<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Catalog;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CatalogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Catalog');
    }

    public function view(AuthUser $authUser, Catalog $catalog): bool
    {
        return $authUser->can('View:Catalog');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Catalog');
    }

    public function update(AuthUser $authUser, Catalog $catalog): bool
    {
        return $authUser->can('Update:Catalog');
    }

    public function delete(AuthUser $authUser, Catalog $catalog): bool
    {
        return $authUser->can('Delete:Catalog');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Catalog');
    }
}
