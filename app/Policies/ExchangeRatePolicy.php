<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExchangeRate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExchangeRatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExchangeRate');
    }

    public function view(AuthUser $authUser, ExchangeRate $exchangeRate): bool
    {
        return $authUser->can('View:ExchangeRate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExchangeRate');
    }

    public function update(AuthUser $authUser, ExchangeRate $exchangeRate): bool
    {
        return $authUser->can('Update:ExchangeRate');
    }

    public function delete(AuthUser $authUser, ExchangeRate $exchangeRate): bool
    {
        return $authUser->can('Delete:ExchangeRate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExchangeRate');
    }
}
