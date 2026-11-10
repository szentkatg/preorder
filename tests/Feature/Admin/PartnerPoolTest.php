<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ErpPartnerPool;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use App\Models\PriceList;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Imports\PartnerPoolImporter;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerPoolTest extends TestCase
{
    use RefreshDatabase;

    private string $batch;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $this->batch = (string) Str::uuid();
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => $this->batch, 'synced_at' => now(), 'row_count' => 1, 'partner_count' => 1]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $language = Language::create(['code' => 'HU', 'name' => 'Magyar', 'active' => true]);
        $list = PriceList::create(['code' => 'POOL-HU', 'name' => 'Nagyker', 'type' => 'wholesale', 'currency_id' => $currency->id, 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools', 'active' => true]);
        $sheet = OrderSheetType::create(['code' => 'B', 'name' => 'Táska', 'active' => true]);
        $this->input = ['target_type' => 'customer', 'country' => 'hu', 'price_list_id' => $list->id,
            'currency_id' => $currency->id, 'language_id' => $language->id, 'brand_ids' => [$brand->id],
            'order_sheet_type_ids' => [$sheet->id], 'allow_assortment_ordering' => false];
    }

    private function row(array $attributes = []): PartnerPool
    {
        return PartnerPool::create(array_replace(['batch_id' => $this->batch, 'row_key' => hash('sha256', Str::uuid()),
            'erp_partner_code' => 'P000001', 'partner_name' => 'ERP partner', 'short_name' => 'ERP', 'partner_type' => 'both',
            'partner_email' => 'partner@example.invalid', 'partner_phone' => '0012345', 'partner_active' => true,
            'sales_rep_erp_partner_code' => 'P000444', 'addrid' => '000123', 'address_name' => 'ERP üzlet', 'country' => 'HU',
            'zip' => '0123', 'city' => 'Budapest', 'street' => 'Minta utca 1.', 'contact_name' => 'Kapcsolattartó',
            'address_email' => 'bolt@example.invalid', 'address_phone' => '006789', 'address_active' => true,
            'is_default' => true, 'source_extracted_at' => now()], $attributes));
    }

    private function import(PartnerPool $row, array $override = []): array
    {
        return app(PartnerPoolImporter::class)->import($row->id, $this->batch, array_replace($this->input, $override));
    }

    public function test_creates_customer_and_selected_address_without_accounts_or_unselected_addresses(): void
    {
        $row = $this->row();
        $this->row(['addrid' => '000124', 'address_name' => 'Másik üzlet']);
        $result = $this->import($row);
        $this->assertSame(['partner', 'address'], $result['created']);
        $this->assertDatabaseHas('partners', ['erp_partner_code' => 'P000001', 'sales_rep_erp_partner_code' => 'P000444']);
        $this->assertDatabaseHas('partner_addresses', ['addrid' => '000123', 'country' => 'HU', 'zip' => '0123']);
        $this->assertDatabaseCount('partner_addresses', 1);
        $this->assertDatabaseCount('partner_address_brand', 1);
        $this->assertDatabaseCount('partner_address_order_sheet_type', 1);
        $this->assertDatabaseCount('partner_users', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseHas('partnerpool_import_logs', ['erp_partner_code' => 'P000001', 'target_type' => 'customer']);
    }

    public function test_supplier_and_both_destinations_are_supported(): void
    {
        $row = $this->row();
        $result = $this->import($row, ['target_type' => 'both']);
        $this->assertSame(['partner', 'address', 'supplier'], $result['created']);
        $this->assertDatabaseHas('suppliers', ['erp_partner_code' => 'P000001', 'addrid' => '000123', 'country_code' => 'HU']);
        $supplierOnly = $this->row(['erp_partner_code' => 'S000002', 'addrid' => null, 'address_name' => null, 'partner_type' => 'supplier']);
        $this->import($supplierOnly, ['target_type' => 'supplier', 'country' => 'CN']);
        $this->assertDatabaseCount('partners', 1);
        $this->assertDatabaseHas('suppliers', ['erp_partner_code' => 'S000002', 'country_code' => 'CN']);
    }

    public function test_existing_master_data_and_address_permissions_are_never_overwritten(): void
    {
        $row = $this->row();
        $this->import($row, ['target_type' => 'both']);
        $partner = Partner::first();
        $partner->update(['name' => 'Kézzel javított']);
        $address = PartnerAddress::first();
        $address->update(['street' => 'Helyi cím', 'active' => false]);
        $address->brands()->detach();
        $supplier = Supplier::first();
        $supplier->update(['name' => 'Helyi beszállító']);
        $result = $this->import($row, ['target_type' => 'both']);
        $this->assertSame([], $result['created']);
        $this->assertSame('Kézzel javított', $partner->fresh()->name);
        $this->assertSame('Helyi cím', $address->fresh()->street);
        $this->assertFalse($address->fresh()->active);
        $this->assertDatabaseCount('partner_address_brand', 0);
        $this->assertSame('Helyi beszállító', $supplier->fresh()->name);
        $this->assertDatabaseCount('partners', 1);
        $this->assertDatabaseCount('partner_addresses', 1);
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_an_address_owned_by_another_partner_is_not_moved_or_duplicated(): void
    {
        $row = $this->row();
        $other = Partner::create(['erp_partner_code' => 'OTHER', 'name' => 'Másik partner']);
        $old = PartnerAddress::create(['partner_id' => $other->id, 'addrid' => $row->addrid, 'name' => 'Régi cím']);
        try {
            $this->import($row);
            $this->fail('Conflicting address accepted');
        } catch (ValidationException) {
        }
        $this->assertSame($other->id, $old->fresh()->partner_id);
        $this->assertDatabaseCount('partners', 1);
        $this->assertDatabaseCount('partner_addresses', 1);
        $this->assertDatabaseCount('partnerpool_import_logs', 0);
    }

    public function test_stale_snapshots_and_inactive_or_incompatible_types_are_rejected(): void
    {
        $row = $this->row();
        try {
            app(PartnerPoolImporter::class)->import($row->id, (string) Str::uuid(), $this->input);
            $this->fail();
        } catch (ValidationException) {
        }
        foreach ([['partner_active' => false], ['partner_active' => true, 'address_active' => false],
            ['address_active' => true, 'partner_type' => 'supplier']] as $change) {
            $row->update($change);
            try {
                $this->import($row);
                $this->fail();
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('partners', 0);
    }

    public function test_currency_country_and_address_permissions_are_validated_before_creation(): void
    {
        $row = $this->row(['country' => '36']);
        $foreign = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'active' => true]);
        foreach ([['country' => '36'], ['currency_id' => $foreign->id], ['brand_ids' => []], ['order_sheet_type_ids' => [9999]]] as $bad) {
            try {
                $this->import($row, $bad);
                $this->fail();
            } catch (ValidationException) {
            }
        }
        $this->assertNull(app(PartnerPoolImporter::class)->defaults($row, 'customer')['country']);
        $this->assertDatabaseCount('partners', 0);
    }

    public function test_native_create_permissions_are_required_and_failures_roll_back_all_targets(): void
    {
        $row = $this->row();
        $user = User::factory()->create();
        $role = Role::findOrCreate('Pool operator', 'web');
        foreach (['View:ErpPartnerPool', 'Import:PartnerPool', 'Create:Partner'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $user->assignRole($role);
        $this->actingAs($user);
        try {
            $this->import($row);
            $this->fail();
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('partners', 0);
        $role->givePermissionTo(Permission::findOrCreate('Create:PartnerAddress', 'web'));
        try {
            $this->import($row, ['target_type' => 'both']);
            $this->fail();
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('partner_addresses', 0);
        $this->assertDatabaseCount('partnerpool_import_logs', 0);
    }

    public function test_page_only_lists_the_active_snapshot_and_opens_the_import_modal(): void
    {
        $row = $this->row();
        $old = $this->row(['batch_id' => (string) Str::uuid(), 'erp_partner_code' => 'OLD']);
        $page = Livewire::test(ErpPartnerPool::class)->assertSuccessful()->assertCanSeeTableRecords([$row])->assertCanNotSeeTableRecords([$old])
            ->mountTableAction('importPartner', $row)->assertTableActionDataSet(['source_batch_id' => $this->batch, 'target_type' => 'both']);
        $page->setTableActionData(['source_batch_id' => $this->batch] + $this->input)->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertDatabaseCount('partners', 1);
        $this->assertDatabaseCount('partner_addresses', 1);
    }

    public function test_users_without_pool_permissions_cannot_import(): void
    {
        $row = $this->row();
        $this->actingAs(User::factory()->create());
        $this->assertFalse(ErpPartnerPool::canAccess());
        try {
            $this->import($row);
            $this->fail();
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('partners', 0);
    }

    public function test_snapshot_replacement_does_not_update_imported_masters(): void
    {
        $row = $this->row();
        $this->import($row);
        $oldName = Partner::first()->name;
        $newBatch = (string) Str::uuid();
        $this->row(['batch_id' => $newBatch, 'partner_name' => 'ERP megváltozott']);
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => $newBatch]);
        PartnerPool::where('batch_id', $this->batch)->delete();
        $this->assertSame($oldName, Partner::first()->name);
        $this->assertDatabaseCount('partnerpool_import_logs', 1);
        $this->assertSame($newBatch, PartnerPool::currentSnapshot()->first()->batch_id);
    }

    public function test_supplier_only_operator_gets_only_the_supplier_target_and_no_customer_records(): void
    {
        $row = $this->row(['street' => str_repeat('A', 300)]);
        $user = User::factory()->create();
        foreach (['View:ErpPartnerPool', 'Import:PartnerPool', 'Create:Supplier'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);

        Livewire::test(ErpPartnerPool::class)->mountTableAction('importPartner', $row)
            ->assertTableActionDataSet(['target_type' => 'supplier'])
            ->setTableActionData(['source_batch_id' => $this->batch, 'target_type' => 'supplier', 'country' => 'HU'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('partner_addresses', 0);
    }
}
