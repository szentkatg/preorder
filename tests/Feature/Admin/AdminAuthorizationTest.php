<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\StockOrderProportioning;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\Languages\LanguageResource;
use App\Models\Language;
use App\Models\PartnerUser;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield as Shield;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

        $this->assertTrue(Gate::forUser($user)->denies('update', $role));
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

    public function test_only_users_with_an_admin_role_can_access_the_admin_panel(): void
    {
        $user = User::factory()->create();
        $adminPanel = Filament::getPanel('admin');

        $this->assertFalse($user->canAccessPanel($adminPanel));

        $user->assignRole(Role::findOrCreate('test_admin', 'web'));

        $this->assertTrue($user->canAccessPanel($adminPanel));
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

    public function test_only_super_admin_can_open_admin_user_management(): void
    {
        $regularUser = User::factory()->create();
        $regularUser->assignRole(Role::findOrCreate('test_admin', 'web'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->assertContains(AdminUserResource::class, Filament::getPanel('admin')->getResources());

        $this->actingAs($regularUser)
            ->get('/admin/admin-users')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/admin/admin-users')
            ->assertOk();
    }

    public function test_super_admin_can_create_an_admin_user_with_a_role(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $assignedRole = Role::findOrCreate('order_manager', 'web');

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAdminUser::class)
            ->fillForm([
                'name' => 'Rendelés kezelő',
                'email' => 'order.manager@example.com',
                'password' => 'secret-password',
                'roles' => [$assignedRole->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdUser = User::query()
            ->where('email', 'order.manager@example.com')
            ->firstOrFail();

        $this->assertTrue($createdUser->hasRole('order_manager'));
    }

    public function test_super_admin_cannot_change_own_roles_or_delete_self(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAdminUser::class, [
            'record' => $superAdmin->getRouteKey(),
        ])->assertFormFieldIsDisabled('roles');

        $this->assertTrue(Gate::forUser($superAdmin)->denies('delete', $superAdmin));
    }

    public function test_resource_permissions_control_list_and_record_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_rep', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(LanguageResource::canViewAny());
        $this->get('/admin/languages')->assertForbidden();
        $this->assertFalse(Gate::forUser($user)->allows('update', new Language));

        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Language', 'web'));

        $this->assertTrue(LanguageResource::canViewAny());
        $this->get('/admin/languages')->assertOk();
        $this->assertFalse(Gate::forUser($user)->allows('update', new Language));

        $user->givePermissionTo(Permission::findOrCreate('Update:Language', 'web'));

        $this->assertTrue(Gate::forUser($user)->allows('update', new Language));
    }

    public function test_custom_page_permission_controls_page_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_rep', 'web'));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(StockOrderProportioning::canAccess());

        $user->givePermissionTo(
            Permission::findOrCreate('View:StockOrderProportioning', 'web'),
        );

        $this->assertTrue(StockOrderProportioning::canAccess());
    }

    public function test_every_managed_resource_and_page_has_authorization_enabled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $resources = collect(Shield::getResources());
        $pages = collect(Shield::getPages());

        $this->assertCount(25, $resources);
        $this->assertCount(5, $pages);

        foreach ($resources as $resource) {
            $policy = Gate::getPolicyFor($resource['modelFqcn']);

            $this->assertNotNull($policy, "Missing policy for {$resource['modelFqcn']}");
            $policyClass = $policy::class;

            foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'] as $method) {
                $this->assertTrue(
                    method_exists($policy, $method),
                    "Missing {$method} method on {$policyClass}",
                );
            }
        }

        foreach ($pages->keys() as $pageClass) {
            $this->assertContains(
                HasPageShield::class,
                class_uses_recursive($pageClass),
                "Missing page authorization on {$pageClass}",
            );
        }
    }
}
