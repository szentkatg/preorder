<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerRepresentativeSortingTest extends TestCase
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

    private function partner(string $code, string $name, ?string $representative = null): Partner
    {
        return Partner::create(['erp_partner_code' => $code, 'name' => $name,
            'sales_rep_erp_partner_code' => $representative, 'active' => true]);
    }

    public function test_partner_list_sorts_by_representative_name_not_partner_name_code_or_id(): void
    {
        $this->partner('REP-A', 'Zita');
        $this->partner('REP-Z', 'Anna');
        $zitaCustomer = $this->partner('CUSTOMER-A', 'Customer Alpha', 'REP-A');
        $annaCustomer = $this->partner('CUSTOMER-Z', 'Customer Zulu', 'REP-Z');

        $page = Livewire::test(ListPartners::class)->searchTableColumns(['name' => 'Customer'])
            ->assertCountTableRecords(2)
            ->assertTableColumnStateSet('salesRepresentative.name', 'Anna', $annaCustomer)
            ->assertTableColumnStateSet('salesRepresentative.name', 'Zita', $zitaCustomer);
        $page->sortTable('salesRepresentative.name', 'asc')
            ->assertCanSeeTableRecords([$annaCustomer, $zitaCustomer], inOrder: true);
        $this->assertSame([$annaCustomer->id, $zitaCustomer->id], $page->instance()->getTableQueryForExport()->pluck('partners.id')->all());
        $page->sortTable('salesRepresentative.name', 'desc')
            ->assertCanSeeTableRecords([$zitaCustomer, $annaCustomer], inOrder: true);
        $this->assertSame([$zitaCustomer->id, $annaCustomer->id], $page->instance()->getTableQueryForExport()->pluck('partners.id')->all());
    }

    public function test_missing_representatives_do_not_drop_or_duplicate_partners(): void
    {
        $this->partner('REP', 'Anna');
        $assigned = $this->partner('CUSTOMER-A', 'Customer assigned', 'REP');
        $missing = [
            $this->partner('CUSTOMER-N', 'Customer null'),
            $this->partner('CUSTOMER-E', 'Customer empty', ''),
            $this->partner('CUSTOMER-S', 'Customer spaces', '   '),
            $this->partner('CUSTOMER-U', 'Customer unknown', 'NOT-IN-MASTER'),
        ];
        $page = Livewire::test(ListPartners::class)->searchTableColumns(['name' => 'Customer']);
        foreach (['asc', 'desc'] as $direction) {
            $page->sortTable('salesRepresentative.name', $direction)->assertCountTableRecords(5)
                ->assertCanSeeTableRecords([$assigned, ...$missing]);
            $ids = $page->instance()->getTableQueryForExport()->pluck('partners.id')->all();
            $this->assertCount(5, array_unique($ids));
            $this->assertSame($assigned->id, $direction === 'asc' ? $ids[4] : $ids[0]);
        }
    }

    public function test_self_representing_partners_do_not_make_the_sort_subquery_constant(): void
    {
        $self = $this->partner('REP-Z', 'Zita', 'REP-Z');
        $this->partner('REP-A', 'Anna');
        $customer = $this->partner('CUSTOMER', 'Customer', 'REP-A');
        $page = Livewire::test(ListPartners::class);
        $column = $page->instance()->getTable()->getColumn('salesRepresentative.name');
        $query = Partner::query()->whereIn('partners.id', [$self->id, $customer->id]);
        $column->applySort($query, 'asc');
        $this->assertSame([$customer->id, $self->id], $query->pluck('partners.id')->all());
        $query->reorder();
        $column->applySort($query, 'desc');
        $this->assertSame([$self->id, $customer->id], $query->pluck('partners.id')->all());
    }
}
