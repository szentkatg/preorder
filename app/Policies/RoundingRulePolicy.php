<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RoundingRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RoundingRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RoundingRule');
    }

    public function view(AuthUser $authUser, RoundingRule $roundingRule): bool
    {
        return $authUser->can('View:RoundingRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RoundingRule');
    }

    public function update(AuthUser $authUser, RoundingRule $roundingRule): bool
    {
        return $authUser->can('Update:RoundingRule');
    }

    public function delete(AuthUser $authUser, RoundingRule $roundingRule): bool
    {
        return $authUser->can('Delete:RoundingRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RoundingRule');
    }
}
