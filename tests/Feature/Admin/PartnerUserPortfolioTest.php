<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PartnerUsers\Pages\EditPartnerUser;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    private function partnerUser(): PartnerUser
    {
        $own = Partner::create(['erp_partner_code' => 'OWN', 'name' => 'Felhasználó saját partnere']);

        return PartnerUser::create(['partner_id' => $own->id, 'role' => PartnerUser::ROLE_SALES_REP,
            'name' => 'Portfólió kezelő', 'email' => 'portfolio@example.test', 'password' => 'password', 'active' => true]);
    }
}
