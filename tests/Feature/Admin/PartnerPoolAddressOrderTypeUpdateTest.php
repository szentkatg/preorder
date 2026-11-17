<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ErpPartnerPool;
use App\Filament\Resources\PartnerAddresses\Pages\ListPartnerAddresses;
use App\Models\OrderType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use App\Models\User;
use App\Services\Imports\PartnerPoolAddressOrderTypeUpdater;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerPoolAddressOrderTypeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->batch = (string) Str::uuid();
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => $this->batch]);
    }

    private function address(string $code = 'P001', string $addrid = '00012', array $types = ['VRELO']): PartnerAddress
    {
        $partner = Partner::firstOrCreate(['erp_partner_code' => $code], ['name' => $code, 'active' => true]);
        $address = $partner->addresses()->create(['addrid' => $addrid, 'name' => 'Helyi cím '.$addrid, 'active' => true]);
        $address->orderTypes()->sync(OrderType::whereIn('code', $types)->pluck('id'));

        return $address;
    }

    private function pool(PartnerAddress $address, mixed $codes = ['VRBELO'], array $extra = []): PartnerPool
    {
        return PartnerPool::create(array_replace([
            'batch_id' => $this->batch, 'row_key' => hash('sha256', Str::uuid()),
            'erp_partner_code' => $address->partner->erp_partner_code, 'partner_name' => 'ERP',
            'partner_type' => 'customer', 'partner_active' => true, 'addrid' => $address->addrid,
            'address_name' => 'ERP cím', 'address_active' => true, 'order_type_codes' => $codes,
            'source_extracted_at' => now(),
        ], $extra));
    }

    private function update(array $addresses, string $mode = 'all', ?string $token = null): array
    {
        return app(PartnerPoolAddressOrderTypeUpdater::class)->update(array_map(fn ($address) => $address->id, $addresses), $this->batch, $mode, $token);
    }

    public function test_only_selected_addresses_are_replaced_and_no_other_master_or_pool_data_changes(): void
    {
        $first = $this->address();
        $second = $this->address('P001', '00013');
        $other = $this->address('P002', '00012');
        $this->pool($first, ['vrbelo', 'TSTOCK', 'VRBELO']);
        $this->pool($second, ['TSHARE']);
        $this->pool($other, ['TSHARE']);
        $before = [];
        foreach (['partners', 'partner_addresses', 'partnerpool', 'orders', 'order_items'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $result = $this->update([$first]);
        $this->assertSame(1, $result['updated']);
        $this->assertEqualsCanonicalizing(['VRBELO', 'TSTOCK'], $first->orderTypes()->pluck('code')->all());
        $this->assertSame(['VRELO'], $second->orderTypes()->pluck('code')->all());
        $this->assertSame(['VRELO'], $other->orderTypes()->pluck('code')->all());
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame(1, $this->update([$first])['unchanged']);
    }

    public function test_empty_mode_fills_only_unconfigured_addresses(): void
    {
        $filled = $this->address();
        $empty = $this->address('P001', '00013', []);
        $this->pool($filled);
        $this->pool($empty);
        $result = $this->update([$filled, $empty], 'empty');
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['already_filled']);
        $this->assertSame(['VRELO'], $filled->orderTypes()->pluck('code')->all());
        $this->assertSame(['VRBELO'], $empty->orderTypes()->pluck('code')->all());
    }

    public function test_missing_empty_and_inactive_sources_are_skipped_without_revoking_permissions(): void
    {
        $addresses = [];
        foreach (range(1, 5) as $i) {
            $addresses[] = $this->address('P001', (string) $i);
        }
        $this->pool($addresses[1], []);
        $this->pool($addresses[2], null);
        $this->pool($addresses[3], ['VRBELO'], ['address_active' => false]);
        $this->pool($addresses[4], ['VRBELO'], ['partner_type' => 'supplier']);
        $result = $this->update($addresses);
        $this->assertSame(1, $result['missing_source']);
        $this->assertSame(2, $result['empty_source']);
        $this->assertSame(2, $result['inactive_source']);
        foreach ($addresses as $address) {
            $this->assertSame(['VRELO'], $address->orderTypes()->pluck('code')->all());
        }
    }

    public function test_unknown_inactive_malformed_or_ambiguous_source_rolls_back_all_changes(): void
    {
        $first = $this->address();
        $second = $this->address('P001', '00013');
        $this->pool($first);
        $row = $this->pool($second, ['UNKNOWN']);
        OrderType::where('code', 'TSHARE')->update(['active' => false]);
        foreach ([['UNKNOWN'], ['TSHARE'], [17], ['bad' => 'VRBELO']] as $codes) {
            $row->update(['order_type_codes' => $codes]);
            try {
                $this->update([$first, $second]);
                $this->fail('Invalid pool permissions accepted.');
            } catch (DomainException) {
                $this->assertSame(['VRELO'], $first->orderTypes()->pluck('code')->all());
                $this->assertSame(['VRELO'], $second->orderTypes()->pluck('code')->all());
            }
        }
        $row->update(['order_type_codes' => ['VRBELO']]);
        $this->pool($second, ['TSTOCK']);
        $this->expectException(DomainException::class);
        $this->update([$first, $second]);
    }

    public function test_snapshot_change_or_selection_change_rejects_the_update(): void
    {
        $first = $this->address();
        $second = $this->address('P001', '00013');
        $this->pool($first);
        $this->pool($second);
        $token = PartnerPoolAddressOrderTypeUpdater::selectionToken([$first->id], $this->batch);
        try {
            $this->update([$second], token: $token);
            $this->fail('Changed selection accepted.');
        } catch (DomainException) {
            $this->assertSame(['VRELO'], $second->orderTypes()->pluck('code')->all());
        }
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => (string) Str::uuid()]);
        $this->expectException(DomainException::class);
        $this->update([$first]);
    }

    public function test_bulk_action_requires_confirmation_and_preserves_filters(): void
    {
        $address = $this->address();
        $this->pool($address);
        $page = Livewire::test(ListPartnerAddresses::class)->searchTable('Helyi')
            ->mountTableBulkAction('updateOrderTypesFromPool', [$address]);
        $page->callMountedTableBulkAction()->assertHasTableBulkActionErrors(['confirmed']);
        $this->assertSame(['VRELO'], $address->orderTypes()->pluck('code')->all());
        $page->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()
            ->assertSet('tableSearch', 'Helyi')->assertDispatched('deselectAllTableRecords');
        $this->assertSame(['VRBELO'], $address->orderTypes()->pluck('code')->all());
    }

    public function test_unauthorized_user_neither_sees_action_nor_can_call_service(): void
    {
        $address = $this->address();
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:PartnerAddress', 'web'));
        $this->actingAs($user);
        Livewire::test(ListPartnerAddresses::class)->assertTableBulkActionHidden('updateOrderTypesFromPool');
        $this->expectException(AuthorizationException::class);
        $this->update([$address]);
    }

    public function test_codes_match_case_insensitively_but_keep_leading_zeroes_and_cancel_changes_nothing(): void
    {
        $address = $this->address('P0001', '00012');
        $this->pool($address, ['VRBELO'], ['erp_partner_code' => ' p0001 ', 'addrid' => ' 00012 ']);
        $this->pool($address, ['TSHARE'], ['addrid' => '12']);
        Livewire::test(ListPartnerAddresses::class)->mountTableBulkAction('updateOrderTypesFromPool', [$address])
            ->setTableBulkActionData(['confirmed' => true])->call('unmountAction');
        $this->assertSame(['VRELO'], $address->orderTypes()->pluck('code')->all());
        $this->assertSame(1, $this->update([$address])['updated']);
        $this->assertSame(['VRBELO'], $address->orderTypes()->pluck('code')->all());
    }

    public function test_operator_with_the_required_permissions_can_use_the_bulk_update(): void
    {
        $address = $this->address();
        $this->pool($address);
        $user = User::factory()->create();
        foreach (['View:ErpPartnerPool', 'Import:PartnerPool', 'ViewAny:PartnerAddress', 'Update:PartnerAddress'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Livewire::test(ListPartnerAddresses::class)->mountTableBulkAction('updateOrderTypesFromPool', [$address])
            ->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame(['VRBELO'], $address->orderTypes()->pluck('code')->all());
    }

    public function test_pool_bulk_action_updates_only_selected_existing_addresses_and_creates_nothing(): void
    {
        $selected = $this->address();
        $other = $this->address('P001', '00013');
        $pool = $this->pool($selected);
        $this->pool($other, ['TSHARE']);
        $missing = $this->pool($selected, ['TSTOCK'], ['addrid' => 'MISSING']);
        Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateOrderTypesFromPool', [$pool, $missing])
            ->assertTableBulkActionDataSet(['mode' => 'all'])->setTableBulkActionData(['confirmed' => true])
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()->assertDispatched('deselectAllTableRecords');
        $this->assertSame(['VRBELO'], $selected->orderTypes()->pluck('code')->all());
        $this->assertSame(['VRELO'], $other->orderTypes()->pluck('code')->all());
        $this->assertDatabaseCount('partner_addresses', 2);
        $this->assertDatabaseCount('partners', 1);
    }

    public function test_select_all_across_pages_respects_filter_and_deselected_address(): void
    {
        foreach (range(1, 52) as $i) {
            $addresses[] = $address = $this->address('P001', 'MATCH-'.$i);
            $this->pool($address);
        }
        $outside = $this->address('P001', 'OUTSIDE');
        $this->pool($outside);
        Livewire::test(ListPartnerAddresses::class)->set('tableRecordsPerPage', 50)->searchTable('MATCH-')
            ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [(string) $addresses[0]->id])
            ->mountTableBulkAction('updateOrderTypesFromPool', [])->setTableBulkActionData(['confirmed' => true])
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame(51, PartnerAddress::whereHas('orderTypes', fn ($query) => $query->where('code', 'VRBELO'))->count());
        $this->assertSame(['VRELO'], $outside->orderTypes()->pluck('code')->all());
        $this->assertSame(['VRELO'], $addresses[0]->orderTypes()->pluck('code')->all());
    }

    public function test_pool_action_aborts_when_snapshot_changes_and_tokens_cannot_be_reused_between_lists(): void
    {
        $address = $this->address();
        $row = $this->pool($address);
        $page = Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateOrderTypesFromPool', [$row]);
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => (string) Str::uuid()]);
        $page->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()
            ->assertTableBulkActionMounted('updateOrderTypesFromPool');
        $this->assertSame(['VRELO'], $address->orderTypes()->pluck('code')->all());
        $this->expectException(DomainException::class);
        app(PartnerPoolAddressOrderTypeUpdater::class)->update([$row->id], $this->batch, 'all',
            PartnerPoolAddressOrderTypeUpdater::selectionToken([$row->id], $this->batch), selectionSource: 'pool');
    }
}
