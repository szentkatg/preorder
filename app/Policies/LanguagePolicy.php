<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Language;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LanguagePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Language');
    }

    public function view(AuthUser $authUser, Language $language): bool
    {
        return $authUser->can('View:Language');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Language');
    }

    public function update(AuthUser $authUser, Language $language): bool
    {
        return $authUser->can('Update:Language');
    }

    public function delete(AuthUser $authUser, Language $language): bool
    {
        return $authUser->can('Delete:Language');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Language');
    }
}
