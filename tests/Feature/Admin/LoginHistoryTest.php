<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Filament\Pages\LoginHistory;
use App\Models\LoginLog;
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

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::findOrCreate('audit-viewer-test', 'web'));
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function allow(): void
    {
        $this->user->givePermissionTo('View:LoginHistory');
    }

    private function log(string $time, string $guard = 'web', string $name = 'Snapshot User', string $ip = '203.0.113.1'): LoginLog
    {
        return LoginLog::create(['guard' => $guard, 'user_id' => 123, 'user_name' => $name,
            'user_email' => 'snapshot@example.test', 'ip_address' => $ip, 'logged_in_at' => CarbonImmutable::parse($time, 'UTC')]);
    }

    public function test_migration_creates_a_separate_permission_without_granting_it_to_normal_roles(): void
    {
        $this->assertTrue(Schema::hasColumns('login_logs', ['guard', 'user_id', 'user_name', 'user_email', 'ip_address', 'logged_in_at']));
        $this->assertTrue(Permission::where('guard_name', 'web')->where('name', 'View:LoginHistory')->exists());
        $this->assertFalse($this->user->can('View:LoginHistory'));
        $this->assertFalse(LoginHistory::canAccess());
        $this->assertFalse(LoginHistory::shouldRegisterNavigation());
        $this->get('/admin/login-history')->assertForbidden();
        Livewire::test(LoginHistory::class)->assertForbidden();
    }

    public function test_authorized_page_is_read_only_searchable_sortable_and_displays_local_time(): void
    {
        $this->allow();
        $old = $this->log('2026-11-19 12:00:00');
        $new = $this->log('2026-11-20 10:20:30', 'partner', 'Partner Snapshot', '2001:db8::1');
        $this->get('/admin/login-history')->assertOk();
        $page = Livewire::test(LoginHistory::class)->assertSuccessful()->assertSee('2026.11.20. 11:20:30')
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
        $this->assertNull($page->instance()->getTable()->getRecordUrl($old));
        $page->sortTable('logged_in_at', 'asc')->assertCanSeeTableRecords([$old, $new], inOrder: true);
        $page->searchTable('2001:db8::1')->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old]);
        $page->searchTable('Partner Snapshot')->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old]);
    }

    public function test_surface_and_calendar_date_filters_use_budapest_time_including_dst_and_until_only(): void
    {
        $this->allow();
        // DST starts on March 29: this local day has only 23 hours.
        $before = $this->log('2026-03-28 22:59:59', 'partner');
        $first = $this->log('2026-03-28 23:00:00', 'partner');
        $last = $this->log('2026-03-29 21:59:59', 'partner');
        $after = $this->log('2026-03-29 22:00:00', 'partner');
        $admin = $this->log('2026-03-29 12:00:00');
        $page = Livewire::test(LoginHistory::class)->filterTable('guard', ['partner'])
            ->filterTable('period', ['from' => '2026-03-29', 'until' => '2026-03-29'])
            ->assertCanSeeTableRecords([$first, $last])->assertCanNotSeeTableRecords([$before, $after, $admin]);
        $page->filterTable('period', ['from' => null, 'until' => '2026-03-29'])
            ->assertCanSeeTableRecords([$before, $first, $last])->assertCanNotSeeTableRecords([$after]);
    }

    public function test_export_contains_all_filtered_rows_and_local_times_not_just_the_current_page(): void
    {
        $this->freezeTime();
        $this->allow();
        $this->log('2026-11-20 10:20:30', 'web', 'Not included');
        $included = collect(range(1, 26))->map(fn () => $this->log('2026-11-20 10:20:30', 'partner'));
        Excel::fake();
        $page = Livewire::test(LoginHistory::class)->filterTable('guard', ['partner'])->set('tableRecordsPerPage', 25);
        $page->callTableAction('exportExcel')->assertHasNoErrors();
        Excel::assertDownloaded('login_logs-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export) use ($included): bool {
            $this->assertSame(26, $export->query()->count());
            $this->assertEqualsCanonicalizing($included->pluck('id')->all(), $export->query()->pluck('id')->all());
            $values = $export->map($included->first());
            $this->assertContains('2026.11.20. 11:20:30', $values);
            $this->assertContains('Partnerportál', $values);

            return true;
        });
    }

    public function test_revoking_permission_blocks_an_already_open_page_and_export(): void
    {
        $this->allow();
        $page = Livewire::test(LoginHistory::class)->assertSuccessful();
        $this->user->revokePermissionTo('View:LoginHistory');
        $page->call('mountAction', 'exportExcel', [], ['table' => true])->assertForbidden();
    }

    public function test_superadmin_has_access_but_partner_portal_login_does_not_grant_admin_access(): void
    {
        $this->user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->assertTrue(LoginHistory::canAccess());
        Livewire::test(LoginHistory::class)->assertSuccessful();
        auth('web')->logout();
        $partner = Partner::create(['name' => 'Partner', 'erp_partner_code' => 'AUDIT-PARTNER']);
        $partnerUser = PartnerUser::create(['name' => 'Partner', 'email' => 'audit-partner@example.test', 'password' => 'password',
            'active' => true, 'role' => 'partner_admin', 'partner_id' => $partner->id]);
        $this->actingAs($partnerUser, 'partner');
        $this->assertFalse(LoginHistory::canAccess());
        $this->get('/admin/login-history')->assertRedirect('/admin/login');
    }
}
