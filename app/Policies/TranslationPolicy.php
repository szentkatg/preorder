<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Translation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TranslationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Translation');
    }

    public function view(AuthUser $authUser, Translation $translation): bool
    {
        return $authUser->can('View:Translation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Translation');
    }

    public function update(AuthUser $authUser, Translation $translation): bool
    {
        return $authUser->can('Update:Translation');
    }

    public function delete(AuthUser $authUser, Translation $translation): bool
    {
        return $authUser->can('Delete:Translation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Translation');
    }
}
