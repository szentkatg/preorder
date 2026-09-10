<?php

namespace Tests\Feature\Admin;

use App\Models\PartnerUser;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_bypasses_admin_authorization_checks(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->assertTrue(Gate::forUser($user)->allows('an-unregistered-admin-ability'));
    }

    public function test_super_admin_role_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('super_admin', 'web');
        $user->assignRole($role);

        $this->assertTrue(Gate::forUser($user)->denies('delete', $role));
        $this->assertTrue(Gate::forUser($user)->denies('deleteAny', Role::class));
    }

    public function test_super_admin_bypass_does_not_apply_to_partner_users(): void
    {
        $partnerUser = new PartnerUser;

        $this->assertFalse(Gate::forUser($partnerUser)->allows('an-unregistered-admin-ability'));
    }

    public function test_role_resource_is_registered_in_the_admin_panel(): void
    {
        $this->assertContains(RoleResource::class, Filament::getPanel('admin')->getResources());
    }

    public function test_only_super_admin_can_open_the_role_management_page(): void
    {
        $regularUser = User::factory()->create();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($regularUser)
            ->get('/admin/shield/roles')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/admin/shield/roles')
            ->assertOk();
    }
}
