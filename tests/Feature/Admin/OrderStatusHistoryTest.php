<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Filament\Pages\OrderStatusHistory;
use App\Models\OrderStatusLog;
use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::findOrCreate('order-audit-viewer-test', 'web'));
        $this->actingAs($this->user);
    }

    private function allow(): void
    {
        $this->user->givePermissionTo('View:OrderStatusHistory');
    }

    private function entry(string $time = '2026-11-26 10:20:30', array $overrides = []): OrderStatusLog
    {
        return OrderStatusLog::create(array_replace([
            'order_id' => 123, 'reference_number' => 'REF-123', 'partner_code' => 'P01', 'partner_name' => 'Partner snapshot',
            'address_code' => 'A01', 'season_code' => 'W27', 'partner_user_id' => 321,
            'user_name' => 'Snapshot User', 'user_email' => 'snapshot@example.test', 'ip_address' => '203.0.113.1',
            'event' => 'submitted', 'source' => 'single', 'from_status' => 'draft', 'to_status' => 'submitted',
            'occurred_at' => CarbonImmutable::parse($time, 'UTC'), 'email_requested' => false, 'email_status' => 'not_requested',
        ], $overrides));
    }

    public function test_migration_creates_logs_and_separate_permission_without_granting_normal_roles_access(): void
    {
        $this->assertTrue(Schema::hasColumns('order_status_logs', ['order_id', 'partner_user_id', 'reference_number', 'event',
            'from_status', 'to_status', 'ip_address', 'email_requested', 'email_status']));
        $this->assertTrue(Permission::where('name', 'View:OrderStatusHistory')->where('guard_name', 'web')->exists());
        $this->assertFalse(OrderStatusHistory::canAccess());
        $this->assertFalse(OrderStatusHistory::shouldRegisterNavigation());
        $this->get('/admin/order-status-history')->assertForbidden();
        Livewire::test(OrderStatusHistory::class)->assertForbidden();
    }

    public function test_authorized_page_is_read_only_sortable_searchable_and_displays_budapest_time(): void
    {
        $this->allow();
        $older = $this->entry('2026-11-25 10:20:30');
        $newer = $this->entry(overrides: ['event' => 'reopened', 'from_status' => 'submitted', 'to_status' => 'draft',
            'email_status' => 'not_applicable', 'reference_number' => 'NEW-REFERENCE', 'ip_address' => '2001:db8::2']);
        $this->get('/admin/order-status-history')->assertOk();
        $page = Livewire::test(OrderStatusHistory::class)->assertSuccessful()->assertSee('2026.11.26. 11:20:30')
            ->assertSee('Visszanyitás')->assertSee('Beküldés')->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
        $this->assertNull($page->instance()->getTable()->getRecordUrl($older));
        $page->sortTable('occurred_at', 'asc')->assertCanSeeTableRecords([$older, $newer], inOrder: true);
        $page->searchTable('NEW-REFERENCE')->assertCanSeeTableRecords([$newer])->assertCanNotSeeTableRecords([$older]);
        $page->searchTable('2001:db8::2')->assertCanSeeTableRecords([$newer])->assertCanNotSeeTableRecords([$older]);
    }

    public function test_filters_combine_event_source_user_email_and_season_even_without_live_user_rows(): void
    {
        $this->allow();
        $included = $this->entry(overrides: ['event' => 'submitted', 'source' => 'bulk', 'email_requested' => true, 'email_status' => 'failed']);
        $other = $this->entry(overrides: ['partner_user_id' => 999, 'event' => 'reopened', 'source' => 'single',
            'season_code' => 'S27', 'email_status' => 'not_applicable']);
        Livewire::test(OrderStatusHistory::class)->filterTable('event', ['submitted'])->filterTable('source', ['bulk'])
            ->filterTable('partner_user_id', [321])->filterTable('email_status', ['failed'])->filterTable('season_code', ['W27'])
            ->assertCanSeeTableRecords([$included])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_calendar_date_filter_respects_budapest_dst_boundaries_and_until_only(): void
    {
        $this->allow();
        $before = $this->entry('2026-03-28 22:59:59');
        $first = $this->entry('2026-03-28 23:00:00');
        $last = $this->entry('2026-03-29 21:59:59');
        $after = $this->entry('2026-03-29 22:00:00');
        $page = Livewire::test(OrderStatusHistory::class)->filterTable('period', ['from' => '2026-03-29', 'until' => '2026-03-29'])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$before, $after]);
        $page->filterTable('period', ['from' => null, 'until' => '2026-03-29'])
            ->assertCanSeeTableRecords([$before, $first, $last])->assertCanNotSeeTableRecords([$after]);
    }

    public function test_excel_export_contains_all_filtered_rows_with_snapshot_labels_and_local_time(): void
    {
        $this->freezeTime();
        $this->allow();
        $this->entry(overrides: ['event' => 'reopened']);
        $included = collect(range(1, 26))->map(fn () => $this->entry());
        Excel::fake();
        Livewire::test(OrderStatusHistory::class)->filterTable('event', ['submitted'])->set('tableRecordsPerPage', 25)
            ->callTableAction('exportExcel')->assertHasNoErrors();
        Excel::assertDownloaded('order_status_logs-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export) use ($included): bool {
            $this->assertSame(26, $export->query()->count());
            $this->assertEqualsCanonicalizing($included->pluck('id')->all(), $export->query()->pluck('id')->all());
            $values = $export->map($included->first());
            $this->assertContains('2026.11.26. 11:20:30', $values);
            $this->assertContains('REF-123', $values);

            return true;
        });
    }

    public function test_revoked_permission_blocks_export_from_an_already_open_page(): void
    {
        $this->allow();
        $page = Livewire::test(OrderStatusHistory::class);
        $this->user->revokePermissionTo('View:OrderStatusHistory');
        $page->call('mountAction', 'exportExcel', [], ['table' => true])->assertForbidden();
    }

    public function test_superadmin_can_read_but_partner_login_does_not_grant_admin_audit_access(): void
    {
        $this->user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->assertTrue(OrderStatusHistory::canAccess());
        Livewire::test(OrderStatusHistory::class)->assertSuccessful();
        auth('web')->logout();
        $partner = Partner::create(['name' => 'Partner', 'erp_partner_code' => 'AUDIT']);
        $partnerUser = PartnerUser::create(['partner_id' => $partner->id, 'role' => 'sales_rep', 'active' => true,
            'name' => 'Rep', 'email' => 'rep@example.test', 'password' => 'password']);
        $this->actingAs($partnerUser, 'partner');
        $this->assertFalse(OrderStatusHistory::canAccess());
        $this->get('/admin/order-status-history')->assertRedirect('/admin/login');
    }
}
