<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ErpPartnerPool;
use App\Models\Partner;
use App\Models\PartnerPool;
use App\Models\PartnerPoolSyncState;
use App\Models\PartnerUser;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Imports\PartnerPoolSalesRepresentativeUpdater;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerPoolSalesRepresentativeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->batch = (string) Str::uuid();
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => $this->batch, 'synced_at' => now()]);
    }

    private function row(string $code, ?string $representative = 'REP', array $extra = []): PartnerPool
    {
        return PartnerPool::create(array_replace([
            'batch_id' => $this->batch, 'row_key' => hash('sha256', Str::uuid()),
            'erp_partner_code' => $code, 'partner_name' => 'ERP '.$code, 'partner_type' => 'customer',
            'sales_rep_erp_partner_code' => $representative, 'partner_active' => true,
            'addrid' => (string) Str::uuid(), 'address_name' => 'ERP-cím', 'address_active' => true,
            'source_extracted_at' => now(),
        ], $extra));
    }

    private function partner(string $code, ?string $representative = null): Partner
    {
        return Partner::create(['erp_partner_code' => $code, 'name' => 'Helyi '.$code,
            'sales_rep_erp_partner_code' => $representative, 'active' => true, 'email' => 'local@example.test']);
    }

    private function update(array $rows, string $mode = 'empty'): array
    {
        return app(PartnerPoolSalesRepresentativeUpdater::class)->update(array_map(fn ($row) => $row->id, $rows), $this->batch, $mode);
    }

    public function test_empty_mode_only_fills_selected_partners_with_null_empty_or_whitespace_representatives(): void
    {
        $rows = [];
        foreach ([null, '', '   ', 'EXISTING'] as $i => $old) {
            $this->partner('P'.$i, $old);
            $rows[] = $this->row('P'.$i, 'R'.$i);
        }
        $untouched = $this->partner('UNSELECTED');
        $this->row('UNSELECTED');
        $poolBefore = DB::table('partnerpool')->orderBy('id')->get()->toJson();

        $result = $this->update($rows);

        $this->assertSame(3, $result['updated']);
        $this->assertSame(1, $result['already_filled']);
        foreach ([0, 1, 2] as $i) {
            $this->assertDatabaseHas('partners', ['erp_partner_code' => 'P'.$i, 'sales_rep_erp_partner_code' => 'R'.$i,
                'name' => 'Helyi P'.$i, 'email' => 'local@example.test', 'active' => true]);
        }
        $this->assertDatabaseHas('partners', ['erp_partner_code' => 'P3', 'sales_rep_erp_partner_code' => 'EXISTING']);
        $this->assertNull($untouched->fresh()->sales_rep_erp_partner_code);
        $this->assertSame($poolBefore, DB::table('partnerpool')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('partner_addresses', 0);
        $this->assertDatabaseCount('partner_users', 0);
        $this->assertDatabaseCount('partner_user_partners', 0);
    }

    public function test_all_mode_overwrites_only_selected_partners_and_counts_unchanged_records(): void
    {
        $first = $this->partner('P1', 'OLD');
        $same = $this->partner('P2', 'REP');
        $untouched = $this->partner('P3', 'KEEP');
        $this->row('P3', 'CHANGE');
        $result = $this->update([$this->row('P1'), $this->row('P2')], 'all');

        $this->assertSame('REP', $first->fresh()->sales_rep_erp_partner_code);
        $this->assertSame('REP', $same->fresh()->sales_rep_erp_partner_code);
        $this->assertSame('KEEP', $untouched->fresh()->sales_rep_erp_partner_code);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
    }

    public function test_multiple_selected_addresses_update_each_partner_once_and_codes_preserve_leading_zeroes(): void
    {
        $partner = $this->partner('000123');
        $first = $this->row('000123', '  000444  ');
        $second = $this->row('000123', '000444');
        $result = $this->update([$first, $second]);
        $this->assertSame(2, $result['selected_rows']);
        $this->assertSame(1, $result['selected_partners']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('000444', $partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_blank_pool_representatives_do_not_clear_existing_assignments_and_missing_partners_are_not_created(): void
    {
        $rows = [];
        foreach ([null, '', '   '] as $i => $value) {
            $this->partner('P'.$i, 'KEEP');
            $rows[] = $this->row('P'.$i, $value);
        }
        $rows[] = $this->row('NOT-IMPORTED');
        $supplier = Supplier::create(['erp_partner_code' => 'NOT-IMPORTED', 'name' => 'Beszállító']);
        $before = $supplier->fresh()->getAttributes();

        $result = $this->update($rows, 'all');
        $this->assertSame(0, $result['updated']);
        $this->assertSame(3, $result['missing_representative']);
        $this->assertSame(1, $result['missing_partner']);
        $this->assertSame(3, Partner::where('sales_rep_erp_partner_code', 'KEEP')->count());
        $this->assertDatabaseCount('partners', 3);
        $this->assertSame($before, $supplier->fresh()->getAttributes());
    }

    public function test_matching_erp_codes_is_case_insensitive(): void
    {
        $partner = $this->partner('MixedCode');
        $result = $this->update([$this->row('mixedcode')]);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('REP', $partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_numeric_erp_codes_with_different_leading_zeroes_are_distinct_partners(): void
    {
        $first = $this->partner('000123');
        $second = $this->partner('123');
        $result = $this->update([$this->row('000123', '000444'), $this->row('123', '444')]);
        $this->assertSame(2, $result['selected_partners']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame('000444', $first->fresh()->sales_rep_erp_partner_code);
        $this->assertSame('444', $second->fresh()->sales_rep_erp_partner_code);
    }

    public function test_representatives_with_different_leading_zeroes_are_conflicting_not_equal(): void
    {
        $this->partner('P1');
        $first = $this->row('P1', '000444');
        $this->row('P1', '444');
        $this->expectException(DomainException::class);
        $this->update([$first]);
    }

    public function test_an_unselected_conflicting_address_stops_and_rolls_back_the_entire_batch(): void
    {
        $first = $this->partner('GOOD', 'OLD');
        $second = $this->partner('CONFLICT', 'OLD');
        $good = $this->row('GOOD');
        $bad = $this->row('CONFLICT', 'REP1');
        $this->row('CONFLICT', 'REP2');
        try {
            $this->update([$good, $bad], 'all');
            $this->fail('Conflicting partner-level values must not be chosen arbitrarily');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('eltérő', $exception->getMessage());
        }
        $this->assertSame('OLD', $first->fresh()->sales_rep_erp_partner_code);
        $this->assertSame('OLD', $second->fresh()->sales_rep_erp_partner_code);
    }

    public function test_duplicate_master_erp_codes_are_rejected_without_partial_updates(): void
    {
        $this->partner('DUPLICATE', 'OLD');
        $this->partner('duplicate', 'OTHER');
        $this->expectException(DomainException::class);
        $this->update([$this->row('DUPLICATE')], 'all');
    }

    public function test_stale_snapshot_or_missing_selected_rows_are_rejected(): void
    {
        $partner = $this->partner('P1');
        $row = $this->row('P1');
        $service = app(PartnerPoolSalesRepresentativeUpdater::class);
        foreach ([(string) Str::uuid(), $this->batch] as $batch) {
            if ($batch === $this->batch) {
                $row->delete();
            }
            try {
                $service->update([$row->id], $batch, 'all');
                $this->fail('Stale source accepted');
            } catch (DomainException) {
            }
        }
        $this->assertNull($partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_empty_selection_invalid_mode_and_invalid_source_code_cannot_update_anything(): void
    {
        $row = $this->row('P1');
        $partner = $this->partner('P1');
        foreach ([[], [$row->id]] as $ids) {
            try {
                app(PartnerPoolSalesRepresentativeUpdater::class)->update($ids, $this->batch, 'unknown');
                $this->fail();
            } catch (ValidationException) {
            }
        }
        $row->update(['erp_partner_code' => '   ']);
        try {
            $this->update([$row]);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertNull($partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_selection_tokens_are_bound_to_the_selected_rows_batch_and_admin(): void
    {
        $first = $this->row('P1');
        $second = $this->row('P2');
        $token = PartnerPoolSalesRepresentativeUpdater::selectionToken([$first->id], $this->batch);
        $this->expectException(DomainException::class);
        app(PartnerPoolSalesRepresentativeUpdater::class)->update([$second->id], $this->batch, 'all', $token);
    }

    public function test_page_import_and_partner_update_permissions_are_all_required(): void
    {
        $row = $this->row('P1');
        $partner = $this->partner('P1');
        $permissions = ['View:ErpPartnerPool', 'Import:PartnerPool', 'Update:Partner'];
        foreach ($permissions as $missing) {
            $user = User::factory()->create();
            $user->assignRole(Role::findOrCreate('pool_operator', 'web'));
            foreach (array_diff($permissions, [$missing]) as $permission) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            $this->actingAs($user);
            try {
                $this->update([$row]);
                $this->fail('Missing permission accepted: '.$missing);
            } catch (AuthorizationException) {
            }
        }
        $this->assertNull($partner->fresh()->sales_rep_erp_partner_code);
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('pool_operator', 'web'));
        foreach ($permissions as $permission) {
            $operator->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($operator);
        $this->assertSame(1, $this->update([$row])['updated']);
    }

    public function test_bulk_action_defaults_to_empty_requires_confirmation_and_preserves_other_partners(): void
    {
        $partner = $this->partner('P1');
        $untouched = $this->partner('P2');
        $row = $this->row('P1');
        $this->row('P2');
        $page = Livewire::test(ErpPartnerPool::class)
            ->mountTableBulkAction('updateSalesRepresentatives', [$row])
            ->assertTableBulkActionDataSet(['mode' => 'empty', 'source_batch_id' => $this->batch, 'confirmed' => false]);
        $this->assertTrue($page->instance()->getMountedAction()->isConfirmationRequired());
        $page->callMountedTableBulkAction()->assertHasTableBulkActionErrors(['confirmed']);
        $this->assertNull($partner->fresh()->sales_rep_erp_partner_code);
        $page->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame('REP', $partner->fresh()->sales_rep_erp_partner_code);
        $this->assertNull($untouched->fresh()->sales_rep_erp_partner_code);
        $page->assertDispatched('deselectAllTableRecords');
    }

    public function test_cancelling_the_modal_changes_nothing(): void
    {
        $partner = $this->partner('P1', 'OLD');
        Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateSalesRepresentatives', [$this->row('P1')])
            ->setTableBulkActionData(['mode' => 'all', 'confirmed' => true])->call('unmountAction');
        $this->assertSame('OLD', $partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_read_only_pool_user_cannot_see_or_mount_the_bulk_update(): void
    {
        $row = $this->row('P1');
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findOrCreate('pool_viewer', 'web'));
        $viewer->givePermissionTo(Permission::findOrCreate('View:ErpPartnerPool', 'web'));
        $this->actingAs($viewer);
        Livewire::test(ErpPartnerPool::class)->assertTableBulkActionHidden('updateSalesRepresentatives')
            ->mountTableBulkAction('updateSalesRepresentatives', [$row])->assertTableBulkActionNotMounted('updateSalesRepresentatives');
    }

    public function test_all_mode_works_from_the_bulk_modal_and_retains_width_and_search_preferences(): void
    {
        $partner = $this->partner('P1', 'OLD');
        $row = $this->row('P1');
        $widths = ['erp-partner-code' => 120, 'partner-name' => 200];
        $page = Livewire::test(ErpPartnerPool::class)->call('savePricingColumnWidths', $widths)->searchTable('P1')
            ->mountTableBulkAction('updateSalesRepresentatives', [$row])
            ->setTableBulkActionData(['mode' => 'all', 'confirmed' => true])
            ->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors()
            ->assertSet('pricingColumnWidths', $widths)->assertSet('tableSearch', 'P1');
        $this->assertSame('REP', $partner->fresh()->sales_rep_erp_partner_code);
        $page->assertSet('mountedActions', []);
    }

    public function test_select_all_only_updates_the_filtered_selection_across_pages_and_respects_deselection(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->partner('MATCH-'.$i);
            $rows[] = $this->row('MATCH-'.$i);
        }
        $outside = $this->partner('OUTSIDE');
        $this->row('OUTSIDE');

        Livewire::test(ErpPartnerPool::class)->set('tableRecordsPerPage', 50)->searchTable('MATCH-')
            ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [(string) $rows[0]->id])
            ->mountTableBulkAction('updateSalesRepresentatives', [])
            ->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();

        $this->assertSame(54, Partner::where('sales_rep_erp_partner_code', 'REP')->count());
        $this->assertDatabaseHas('partners', ['erp_partner_code' => 'MATCH-0', 'sales_rep_erp_partner_code' => null]);
        $this->assertNull($outside->fresh()->sales_rep_erp_partner_code);
    }

    public function test_snapshot_replacement_after_opening_the_modal_aborts_without_modifying_any_partner(): void
    {
        $partner = $this->partner('P1', 'OLD');
        $page = Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateSalesRepresentatives', [$this->row('P1')]);
        PartnerPoolSyncState::findOrFail(1)->update(['active_batch_id' => (string) Str::uuid()]);
        $page->setTableBulkActionData(['mode' => 'all', 'confirmed' => true])->callMountedTableBulkAction()
            ->assertTableBulkActionMounted('updateSalesRepresentatives');
        $this->assertSame('OLD', $partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_a_selection_change_after_opening_the_modal_is_rejected(): void
    {
        $first = $this->partner('P1');
        $second = $this->partner('P2');
        $page = Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateSalesRepresentatives', [$this->row('P1')]);
        $page->selectTableRecords([$this->row('P2')])->setTableBulkActionData(['confirmed' => true])
            ->callMountedTableBulkAction()->assertTableBulkActionMounted('updateSalesRepresentatives');
        $this->assertNull($first->fresh()->sales_rep_erp_partner_code);
        $this->assertNull($second->fresh()->sales_rep_erp_partner_code);
    }

    public function test_empty_mode_rechecks_the_current_master_value_when_the_modal_is_confirmed(): void
    {
        $partner = $this->partner('P1');
        $page = Livewire::test(ErpPartnerPool::class)->mountTableBulkAction('updateSalesRepresentatives', [$this->row('P1')]);
        $partner->update(['sales_rep_erp_partner_code' => 'SET-BY-OTHER-ADMIN']);
        $page->setTableBulkActionData(['confirmed' => true])->callMountedTableBulkAction()->assertHasNoTableBulkActionErrors();
        $this->assertSame('SET-BY-OTHER-ADMIN', $partner->fresh()->sales_rep_erp_partner_code);
    }

    public function test_updating_a_representative_changes_inherited_access_without_changing_user_grants(): void
    {
        $oldRep = $this->partner('OLD-REP');
        $newRep = $this->partner('NEW-REP');
        $partner = $this->partner('P1', 'OLD-REP');
        $users = [];
        foreach ([$oldRep, $newRep] as $i => $rep) {
            $user = PartnerUser::create(['partner_id' => $rep->id, 'role' => 'sales_rep', 'name' => 'Képviselő '.$i,
                'email' => 'rep'.$i.'@example.test', 'password' => 'password', 'active' => true]);
            $users[] = $user;
        }
        $this->assertTrue($users[0]->canAccessPartner($partner));
        $this->assertFalse($users[1]->canAccessPartner($partner));
        $this->update([$this->row('P1', 'NEW-REP')], 'all');
        $this->assertFalse($users[0]->fresh()->canAccessPartner($partner->fresh()));
        $this->assertTrue($users[1]->fresh()->canAccessPartner($partner->fresh()));
        $this->assertDatabaseCount('partner_user_partners', 0);
    }
}
