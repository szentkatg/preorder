<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PartnerUsers\Pages\EditPartnerUser;
use App\Filament\Resources\PartnerUsers\Pages\ViewPartnerUser;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerUserPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
    }

    public function test_selector_searches_by_erp_code_and_explains_the_complete_dynamic_portfolio(): void
    {
        $representative = Partner::create(['erp_partner_code' => 'P000444', 'name' => 'Képviselő']);
        $futureRepresentative = Partner::create(['erp_partner_code' => 'P000555', 'name' => 'Későbbi képviselő']);
        Partner::create(['erp_partner_code' => 'P001', 'name' => 'Vevő', 'sales_rep_erp_partner_code' => 'P000444']);
        $user = $this->partnerUser();
        Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])->assertSuccessful()
            ->assertSee('a később felvett partnerekkel együtt')
            ->assertFormFieldExists('partners', 'form', function (Select $field) use ($representative, $futureRepresentative): bool {
                $results = $field->getSearchResults('P000444');
                $this->assertSame([$representative->id => 'P000444 - Képviselő — teljes partnerkör (1 partner)'], $results);
                $future = $field->getSearchResults('P000555');
                $this->assertArrayHasKey($futureRepresentative->id, $future, 'A representative can be assigned before the first customer exists');

                return true;
            });
    }

    public function test_saving_the_representative_once_grants_future_partners_and_removing_it_revokes_them(): void
    {
        $representative = Partner::create(['erp_partner_code' => 'P000444', 'name' => 'Képviselő']);
        $user = $this->partnerUser();
        $page = Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['partners' => [$representative->id]])->call('save')->assertHasNoFormErrors();
        $newPartner = Partner::create(['erp_partner_code' => 'NEW', 'name' => 'Új vevő', 'sales_rep_erp_partner_code' => 'P000444']);
        $this->assertTrue($user->fresh()->canAccessPartner($newPartner));
        $this->assertSame([$representative->id], $user->partners()->pluck('partners.id')->all());
        $page->set('data.partners', [])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($user->fresh()->canAccessPartner($newPartner));
        $this->assertSame(0, $user->partners()->count());
    }

    public function test_clear_all_requires_confirmation_and_cancelling_preserves_the_assignments(): void
    {
        $user = $this->partnerUser();
        $partner = Partner::create(['erp_partner_code' => 'P001', 'name' => 'Partner']);
        $user->partners()->attach($partner);

        $page = Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])
            ->mountFormComponentAction('partners', 'clearPartnerAssignments')
            ->assertFormComponentActionMounted('partners', 'clearPartnerAssignments');

        $action = $page->instance()->getMountedAction();
        $this->assertTrue($action->isConfirmationRequired());
        $this->assertSame('Összes partner-hozzárendelés törlése?', $action->getModalHeading());
        $this->assertStringContainsString('(1 db)', $action->getModalDescription());

        $this->assertEquals([$partner->id], $page->get('data.partners'));
        $this->assertSame(1, $user->partners()->count());

        $page->unmountFormComponentAction()->call('save')->assertHasNoFormErrors();
        $this->assertSame([$partner->id], $user->partners()->pluck('partners.id')->all());
    }

    public function test_clear_all_is_saved_only_with_the_form_and_does_not_delete_partners_or_other_grants(): void
    {
        $user = $this->partnerUser();
        $rep = Partner::create(['erp_partner_code' => 'REP', 'name' => 'Képviselő']);
        $customer = Partner::create(['erp_partner_code' => 'CUSTOMER', 'name' => 'Vevő', 'sales_rep_erp_partner_code' => 'REP']);
        $ownCustomer = Partner::create(['erp_partner_code' => 'OWN-CUSTOMER', 'name' => 'Saját partnerkör', 'sales_rep_erp_partner_code' => 'OWN']);
        $address = PartnerAddress::create(['partner_id' => $user->partner_id, 'addrid' => 'A1', 'name' => 'Cím', 'active' => true]);
        $user->partners()->attach([$rep->id, $customer->id]);
        $user->addresses()->attach($address);
        $otherUser = PartnerUser::create(['partner_id' => $rep->id, 'role' => PartnerUser::ROLE_SALES_REP,
            'name' => 'Másik felhasználó', 'email' => 'other@example.test', 'password' => 'password', 'active' => true]);
        $otherUser->partners()->attach($customer);
        $partnerCount = Partner::count();

        $page = Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])
            ->callFormComponentAction('partners', 'clearPartnerAssignments');
        $this->assertSame([], $page->get('data.partners'));
        $this->assertSame(2, $user->partners()->count(), 'The action must not save or detach immediately');

        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, $user->partners()->count());
        $this->assertSame($partnerCount, Partner::count());
        $this->assertSame([$customer->id], $otherUser->partners()->pluck('partners.id')->all());
        $this->assertSame([$address->id], $user->addresses()->pluck('partner_addresses.id')->all());
        $this->assertSame($user->partner_id, $user->fresh()->partner_id);
        $this->assertFalse($user->fresh()->canAccessPartner($customer));
        $this->assertTrue($user->fresh()->canAccessPartner($ownCustomer));
    }

    public function test_after_clearing_the_list_a_representative_can_be_selected_and_saved(): void
    {
        $user = $this->partnerUser();
        $rep = Partner::create(['erp_partner_code' => 'REP', 'name' => 'Képviselő']);
        $customer = Partner::create(['erp_partner_code' => 'CUSTOMER', 'name' => 'Vevő', 'sales_rep_erp_partner_code' => 'REP']);
        $user->partners()->attach($customer);

        Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])
            ->callFormComponentAction('partners', 'clearPartnerAssignments')
            ->fillForm(['partners' => [$rep->id]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([$rep->id], $user->partners()->pluck('partners.id')->all());
        $this->assertTrue($user->fresh()->canAccessPartner($customer));
    }

    public function test_clearing_an_empty_list_is_safe(): void
    {
        $user = $this->partnerUser();
        Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])
            ->callFormComponentAction('partners', 'clearPartnerAssignments')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, $user->partners()->count());
    }

    public function test_read_only_users_cannot_use_the_clear_action(): void
    {
        $user = $this->partnerUser();
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findOrCreate('portfolio_viewer', 'web'));
        $viewer->givePermissionTo([
            Permission::findOrCreate('ViewAny:PartnerUser', 'web'),
            Permission::findOrCreate('View:PartnerUser', 'web'),
        ]);
        $this->actingAs($viewer);

        Livewire::test(ViewPartnerUser::class, ['record' => $user->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('Összes hozzárendelés törlése')
            ->assertFormComponentActionDoesNotExist('partners', 'clearPartnerAssignments');
        Livewire::test(EditPartnerUser::class, ['record' => $user->getRouteKey()])->assertForbidden();
    }

    private function partnerUser(): PartnerUser
    {
        $own = Partner::create(['erp_partner_code' => 'OWN', 'name' => 'Felhasználó saját partnere']);

        return PartnerUser::create(['partner_id' => $own->id, 'role' => PartnerUser::ROLE_SALES_REP,
            'name' => 'Portfólió kezelő', 'email' => 'portfolio@example.test', 'password' => 'password', 'active' => true]);
    }
}
