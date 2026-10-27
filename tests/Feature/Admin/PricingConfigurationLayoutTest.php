<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingRoundingRules\Pages\ListPricingRoundingRules;
use App\Models\AdminUiPreference;
use App\Models\PricingProject;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingConfigurationLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const PAGES = [ListPricingProjects::class, ListPricingParameters::class, ListPricingRoundingRules::class];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = $this->admin();
        $this->actingAs($this->user);
    }

    public function test_all_three_lists_use_the_shared_resizer_and_reorderable_compact_column_manager(): void
    {
        foreach (self::PAGES as $class) {
            $page = Livewire::test($class)->assertSuccessful()
                ->assertSee('data-pricing-resizable-columns', false)
                ->assertSee('data-pricing-server-widths', false)
                ->assertSee('data-compact-column-manager', false)
                ->assertSee('data-pricing-column-width-reset', false)
                ->assertSee('data-pricing-column-width-fit', false)
                ->assertSee('resizer.addEventListener', false)
                ->assertSee('hasTableStructureChange', false);
            $this->assertTrue($page->instance()->getTable()->hasReorderableColumns());
            foreach ($page->instance()->getTable()->getColumns() as $column) {
                $this->assertTrue($column->isToggleable());
            }
            $this->assertSame('await applyTableColumnManager(); if (! error) close()',
                $page->instance()->getTable()->getColumnManagerApplyAction()->getAlpineClickHandler());
        }
    }

    public function test_width_order_and_visibility_survive_reentry_and_session_loss_independently_per_page(): void
    {
        $keys = [];
        foreach (self::PAGES as $index => $class) {
            $page = Livewire::test($class);
            $columns = array_reverse($page->instance()->tableColumns);
            foreach ($columns as &$column) {
                if ($column['name'] === 'active') {
                    $column['isToggled'] = false;
                }
            }
            unset($column);
            $widths = ['name' => 120 + $index * 20];
            $page->call('savePricingColumnWidths', $widths)->assertHasNoErrors();
            $this->assertArrayNotHasKey('html', $page->effects);
            $page->call('applyTableColumnManager', $columns, true)->assertHasNoErrors();
            $keys[] = $page->instance()->pricingColumnStorageKey();
            session()->flush();
            $fresh = Livewire::test($class);
            $this->assertSame($widths, $fresh->instance()->pricingColumnWidths);
            $this->assertSame(array_column($columns, 'name'), array_column($fresh->instance()->tableColumns, 'name'));
            $this->assertTrue($fresh->instance()->isTableColumnToggledHidden('active'));
            $visible = array_column(array_filter($columns, fn ($column) => ! $column['isHidden'] && $column['isToggled']), 'name');
            $this->assertSame(array_values($visible), array_keys($fresh->instance()->getTable()->getVisibleColumns()));
            // Showing/reordering columns must not reset the widths stored by column key.
            $fresh->call('resetTableColumnManager');
            $this->assertSame($widths, $fresh->instance()->pricingColumnWidths);
            $this->assertFalse($fresh->instance()->isTableColumnToggledHidden('active'));
        }
        $this->assertCount(3, array_unique($keys));
        foreach (self::PAGES as $index => $class) {
            $this->assertSame(['name' => 120 + $index * 20], Livewire::test($class)->instance()->pricingColumnWidths);
        }
    }

    public function test_layout_is_isolated_between_users_and_width_reset_is_saved_without_rerender(): void
    {
        $other = $this->admin();
        foreach (self::PAGES as $class) {
            $this->actingAs($this->user);
            $page = Livewire::test($class)->call('savePricingColumnWidths', ['name' => 140, 'invalid' => 20, 'too-wide' => 5000]);
            $columns = array_reverse($page->instance()->tableColumns);
            $page->call('applyTableColumnManager', $columns, true);
            session()->flush();
            $this->actingAs($other);
            $otherPage = Livewire::test($class);
            $this->assertSame([], $otherPage->instance()->pricingColumnWidths);
            $this->assertSame($otherPage->instance()->getDefaultTableColumnState(), $otherPage->instance()->tableColumns);
            $otherPage->call('savePricingColumnWidths', ['name' => 220]);
            session()->flush();
            $this->actingAs($this->user);
            $restored = Livewire::test($class);
            $this->assertSame(['name' => 140], $restored->instance()->pricingColumnWidths);
            $this->assertSame(array_column($columns, 'name'), array_column($restored->instance()->tableColumns, 'name'));
            $restored->call('resetPricingColumnWidths');
            $this->assertArrayNotHasKey('html', $restored->effects);
            $this->assertSame([], Livewire::test($class)->instance()->pricingColumnWidths);
        }
        $this->assertSame(3, AdminUiPreference::where('user_id', $other->id)->where('preference_key', 'like', '%_column_widths')->count());
    }

    public function test_resizing_and_reordering_keep_existing_filters_and_record_actions(): void
    {
        $season = Season::create(['code' => 'LAY', 'name' => 'Layout', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Layout', 'status' => 'active', 'active' => true]);
        $page = Livewire::test(ListPricingProjects::class)->filterTable('id', [(string) $project->id]);
        $filters = $page->instance()->tableFilters;
        $page->call('savePricingColumnWidths', ['name' => 150])
            ->call('applyTableColumnManager', array_reverse($page->instance()->tableColumns), true)
            ->assertCanSeeTableRecords([$project]);
        $this->assertSame($filters, $page->instance()->tableFilters);
        $this->assertTrue($page->instance()->getTable()->hasAction('edit'));
        $this->assertTrue($page->instance()->getTable()->hasAction('exportExcel'));
        $this->assertSame($filters, Livewire::test(ListPricingProjects::class)->instance()->tableFilters);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }

    public function test_autofit_reads_the_entire_filtered_result_in_chunks_not_only_the_visible_page(): void
    {
        $season = Season::create(['code' => 'FIT', 'name' => 'Igazítás', 'active' => true]);
        $other = Season::create(['code' => 'OTH', 'name' => 'Más szezon', 'active' => true]);
        for ($i = 1; $i <= 205; $i++) {
            PricingProject::create(['season_id' => $season->id, 'name' => $i === 205
                ? 'A szűrt lista utolsó lapján szereplő leghosszabb árprojekt megnevezése' : 'Árprojekt '.$i,
                'status' => 'active', 'active' => true]);
        }
        PricingProject::create(['season_id' => $other->id, 'name' => 'KIZÁRT — ennél még sokkal hosszabb megnevezés, amely nem befolyásolhatja az igazítást',
            'status' => 'draft', 'active' => true]);
        $page = Livewire::test(ListPricingProjects::class)->filterTable('season_id', [(string) $season->id])
            ->set('tableRecordsPerPage', 50)->sortTable('id')->selectTableRecords([1]);
        $before = $page->instance()->tableFilters;
        $page->call('pricingColumnFitContent')->assertHasNoErrors();
        $content = $page->effects['returns'][0];
        $this->assertCount(205, $content['name']['texts']);
        $this->assertContains('A szűrt lista utolsó lapján szereplő leghosszabb árprojekt megnevezése', $content['name']['texts']);
        $this->assertSame(['Aktív'], $content['status']['texts']);
        $this->assertSame(['0'], $content['products-count']['texts']);
        $this->assertArrayNotHasKey('active', $content); // Icon-only column, measured by its heading/icon.
        $this->assertArrayNotHasKey('closed-at', $content); // Hidden columns keep their saved widths.
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertSame($before, $page->instance()->tableFilters);
        $this->assertSame(['1'], array_map('strval', $page->instance()->selectedTableRecords));
        $this->assertSame(50, $page->instance()->getTableRecordsPerPage());
        $this->assertSame(0, AdminUiPreference::where('preference_key', 'like', '%_column_widths')->count());

        $page->searchTable('utolsó lapján')->call('pricingColumnFitContent');
        $this->assertCount(1, $page->effects['returns'][0]['name']['texts']);
    }

    public function test_autofit_uses_displayed_formatting_and_does_not_send_hidden_values(): void
    {
        $season = Season::create(['code' => 'FMT', 'name' => 'Formátum', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'A<B> & C', 'status' => 'active', 'active' => true,
            'closed_at' => '2026-10-27 13:14:15']);
        $page = Livewire::test(ListPricingProjects::class);
        $columns = $page->instance()->tableColumns;
        foreach ($columns as &$column) {
            if ($column['name'] === 'closed_at') {
                $column['isToggled'] = true;
            }
            if ($column['name'] === 'name') {
                $column['isToggled'] = false;
            }
        }
        unset($column);
        $page->call('applyTableColumnManager', $columns, true)->call('pricingColumnFitContent');
        $content = $page->effects['returns'][0];
        $this->assertArrayNotHasKey('name', $content);
        $dateColumn = (clone $page->instance()->getTable()->getColumn('closed_at'))->record($project);
        $this->assertSame([(string) $dateColumn->formatState($dateColumn->getState())], $content['closed-at']['texts']);
        $this->assertNotSame(['2026-10-27 13:14:15'], $content['closed-at']['texts']);
        $this->assertTrue($content['status']['badge']);
        $this->assertFalse($content['status']['icon']);
        $page->searchTable('NINCS ILYEN')->call('pricingColumnFitContent');
        $this->assertSame([], $page->effects['returns'][0]['status']['texts']);
    }
}
