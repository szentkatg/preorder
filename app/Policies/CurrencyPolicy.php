<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Currency;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CurrencyPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Currency');
    }

    public function view(AuthUser $authUser, Currency $currency): bool
    {
        return $authUser->can('View:Currency');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Currency');
    }

    public function update(AuthUser $authUser, Currency $currency): bool
    {
        return $authUser->can('Update:Currency');
    }

    public function delete(AuthUser $authUser, Currency $currency): bool
    {
        return $authUser->can('Delete:Currency');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Currency');
    }
}
