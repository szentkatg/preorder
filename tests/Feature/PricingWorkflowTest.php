<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Pages\PricingWorkflow;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Filament\Resources\PricingProjects\Pages\EditPricingProject;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingProjects\Pages\SelectPricingProjectProducts;
use App\Filament\Resources\PricingProjects\RelationManagers\CalculationRowsRelationManager;
use App\Filament\Resources\PricingProjects\RelationManagers\ProductsRelationManager;
use App\Models\AdminUiPreference;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\PricingAttentionQuery;
use App\Services\Pricing\PricingWorkflowSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PricingProject $other;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'WF', 'name_hu' => 'Teszt', 'active' => true]);
        $this->product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'WF-1', 'name_hu' => 'Teszt', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Első projekt', 'status' => 'active', 'active' => true]);
        $this->other = PricingProject::create(['season_id' => $season->id, 'name' => 'Másik projekt', 'status' => 'draft', 'active' => true]);
        $this->row('cost', 'HUF', 'calculated', 500);
        $this->row('retail', 'HUF', 'approved', 2000);
        $this->row('outlet', 'HUF', 'calculated', 1800, ['base_price' => 2000, 'outlet_discount_percent' => 20]);
        $this->row('retail', 'EUR', 'modified_approved', 10);
        $this->row('outlet', 'EUR', 'approved_round_3', 8, ['base_price' => 10, 'outlet_discount_percent' => 20]);
        $this->row('wholesale', 'EUR', 'approved_round_2', 5, ['requires_review' => true]);
        $this->row('distributor', 'EUR', 'reviewed', 4);
        $second = $this->product->replicate();
        $second->model_code = 'WF-2';
        $second->save();
        $this->row('cost', 'HUF', 'calculated', 600, ['product_id' => $second->id]);
        $this->row('retail', 'HUF', 'approved', 0, ['product_id' => $second->id]);
        $this->row('retail', 'HUF', 'approved', 3000, ['pricing_project_id' => $this->other->id]);
    }

    private function row(string $type, string $code, string $status, float $price, array $extra = []): PricingCalculationRow
    {
        $currency = Currency::firstOrCreate(['code' => $code], ['name_hu' => $code, 'active' => true]);
        $list = $type === 'cost' ? null : PriceList::firstOrCreate(['code' => $type.'-'.$code],
            ['name_hu' => $type, 'type' => $type, 'currency_id' => $currency->id, 'active' => true]);

        return PricingCalculationRow::create(array_replace(['pricing_project_id' => $this->project->id,
            'product_id' => $this->product->id, 'price_type' => $type, 'currency_id' => $currency->id,
            'price_list_id' => $list?->id, 'status' => $status, 'calculated_price' => $price], $extra));
    }

    public function test_counts_are_project_scoped_and_outlet_is_included_as_a_subset(): void
    {
        $counts = app(PricingWorkflowSummary::class)->forProject($this->project);
        $this->assertSame(['total' => 3, 'approved' => 1, 'pending' => 2,
            'outlet_total' => 1, 'outlet_approved' => 0, 'outlet_pending' => 1], $counts['huf']);
        $this->assertSame(2, $counts['cost']['approved']);
        $this->assertSame(2, $counts['foreign']['approved']);
        $this->assertSame(1, $counts['foreign']['outlet_approved']);
        $this->assertSame(1, $counts['wholesale']['pending']);
        $this->assertSame(1, $counts['distributor']['pending']);
        $this->assertSame(2, $counts['overview']['total']);
        $this->assertSame(2, $counts['overview']['pending']);
        $other = app(PricingWorkflowSummary::class)->forProject($this->other);
        $this->assertSame(1, $other['huf']['approved']);
        $this->assertSame(0, $other['foreign']['total']);
    }

    public function test_missing_selection_never_sums_all_projects_and_switching_replaces_counts(): void
    {
        $page = Livewire::test(PricingWorkflow::class)->assertSuccessful()->assertSet('pricingProjectId', null)
            ->assertSee('Válassz árprojektet.');
        $this->assertNull($page->instance()->workflowSteps['huf']['counts']);
        $page->call('selectPricingProject', $this->project->id)->assertSee('(1 outlet)');
        $this->assertSame(3, $page->instance()->workflowSteps['huf']['counts']['total']);
        $page->call('selectPricingProject', $this->other->id);
        $this->assertSame(1, $page->instance()->workflowSteps['huf']['counts']['total']);
        $this->assertSame(0, $page->instance()->workflowSteps['huf']['counts']['pending']);
        $page->call('selectPricingProject', '');
        $this->assertNull($page->instance()->workflowSteps['huf']['counts']);
    }

    public function test_opening_a_step_reapplies_selected_project_even_after_another_tab_changes_it(): void
    {
        $page = Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id);
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->other->id);
        $page->call('openPricingStep', 'cost')->assertRedirect(PricingCostCalculations::getUrl());
        $this->assertSame($this->project->id, AdminUiPreference::where('preference_key', 'pricing_workspace_project_context')->firstOrFail()->value['project_id']);
        Livewire::test(PricingCostCalculations::class)->assertSet('pricingProjectId', $this->project->id);
    }

    public function test_archive_is_read_only_and_live_refresh_has_a_small_payload_without_snapshots(): void
    {
        $this->project->update(['status' => 'closed']);
        $row = $this->project->calculationRows()->first();
        $row->update(['calculation_snapshot' => ['large_source' => str_repeat('sensitive-source', 100000)]]);
        $before = $this->project->calculationRows()->get()->map->getAttributes()->all();
        $page = Livewire::test(PricingWorkflow::class)->call('setPricingArchive', true)
            ->assertSet('pricingProjectId', $this->project->id)->assertSee('Lezárt árprojekt: csak megtekintés.');
        $this->assertSame(3, $page->instance()->workflowSteps['huf']['counts']['total']);
        $page->call('$refresh')->assertDontSee('sensitive-source');
        $this->assertLessThan(100000, strlen(json_encode(['snapshot' => $page->snapshot, 'effects' => $page->effects])));
        $this->assertSame($before, $this->project->calculationRows()->get()->map->getAttributes()->all());
        $page->call('selectPricingProject', $this->other->id)->assertStatus(422);
    }

    public function test_outlet_approval_with_insufficient_discount_is_pending_even_without_review_flag(): void
    {
        $this->project->calculationRows()->where('price_type', 'outlet')->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
            ->update(['status' => 'approved_round_2', 'requires_review' => false]);
        $this->assertSame(1, app(PricingWorkflowSummary::class)->forProject($this->project)['huf']['outlet_pending']);
    }

    public function test_dashboard_and_destination_permissions_are_enforced_for_direct_requests(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(PricingWorkflow::class)->assertForbidden();
        $user->givePermissionTo(['View:PricingWorkflow', Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web'),
            Permission::findOrCreate('ViewAny:PricingProject', 'web'), Permission::findOrCreate('View:PricingProject', 'web')]);
        $page = Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id)->assertSuccessful();
        $this->assertNull($page->instance()->workflowSteps['cost']['url']);
        $page->call('openPricingStep', 'cost')->assertForbidden();
    }

    public function test_context_cannot_be_overwritten_through_a_livewire_property_update(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PricingWorkflow::class)->set('pricingProjectId', $this->other->id);
    }

    public function test_project_list_filters_by_project_and_season_together(): void
    {
        $season = Season::create(['code' => 'S28', 'name' => 'Nyár', 'active' => true]);
        $third = PricingProject::create(['name' => 'Nyári projekt', 'season_id' => $season->id, 'status' => 'draft', 'active' => true]);
        Livewire::test(ListPricingProjects::class)->assertCanSeeTableRecords([$this->project, $this->other, $third])
            ->filterTable('id', [$this->project->id, $third->id])->assertCanNotSeeTableRecords([$this->other])
            ->filterTable('season_id', [$this->project->season_id])->assertCanSeeTableRecords([$this->project])
            ->assertCanNotSeeTableRecords([$this->other, $third]);
    }

    public function test_calculation_rows_have_no_default_price_type_filter(): void
    {
        $page = Livewire::test(ListPricingCalculationRows::class);
        $this->assertNull($page->instance()->getTableFilterState('price_type')['value'] ?? null);
        $page->assertCanSeeTableRecords($this->project->calculationRows()->get());
        $page->filterTable('price_type', 'cost')->assertCanNotSeeTableRecords($this->project->calculationRows()->where('price_type', 'retail')->get());
    }

    public function test_product_related_tables_use_same_page_sizes_and_default_as_calculations(): void
    {
        $rows = Livewire::test(CalculationRowsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditPricingProject::class]);
        $products = Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditPricingProject::class]);
        $selector = Livewire::test(SelectPricingProjectProducts::class, ['record' => $this->project->id]);
        foreach ([$rows, $products, $selector] as $page) {
            $this->assertSame([50, 100, 250, 500, 'all'], $page->instance()->getTable()->getPaginationPageOptions());
            $this->assertSame(100, $page->instance()->getTableRecordsPerPage());
        }
    }

    public function test_all_six_workflow_cards_link_each_count_to_the_matching_state_and_project(): void
    {
        $page = Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id);
        foreach ($page->instance()->workflowSteps as $key => $step) {
            foreach (['total', 'approved', 'pending'] as $state) {
                parse_str(parse_url($step['countUrls'][$state], PHP_URL_QUERY), $query);
                $this->assertSame((string) $this->project->id, $query['workflowProject']);
                $this->assertSame($state === 'total' ? '' : $state, $query['tableFilters']['workflow_state']['value']);
                $page->assertSee("openPricingStep('{$key}', '{$state}')", false);
                $page->call('openPricingStep', $key, $state)->assertRedirect($step['countUrls'][$state]);
            }
        }
    }

    public function test_workflow_status_link_survives_mount_and_restores_project_from_another_tab(): void
    {
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->other->id);
        $page = Livewire::withQueryParams(['workflowProject' => (string) $this->project->id, 'workflowArchive' => '0',
            'tableFilters' => ['workflow_state' => ['value' => 'approved']]])
            ->test(PricingCostCalculations::class)->assertSet('pricingProjectId', $this->project->id);
        $this->assertSame('approved', $page->instance()->getTableFilterState('workflow_state')['value']);
        $page->assertCanSeeTableRecords($this->project->calculationRows()->where('price_type', 'cost')->get());
        $page->filterTable('workflow_state', 'pending')->assertCountTableRecords(0);
        $page->filterTable('workflow_state', null)->assertCountTableRecords(2);
    }

    public function test_workflow_state_query_agrees_with_model_including_outlet_boundaries(): void
    {
        foreach ([['base_price' => 2000, 'outlet_discount_percent' => 20, 'manual_price' => 1600],
            ['base_price' => 2000, 'outlet_discount_percent' => 20, 'manual_price' => 1600.0001],
            ['base_price' => 2000, 'outlet_discount_percent' => null, 'manual_price' => 1500],
            ['base_price' => 2000, 'outlet_discount_percent' => 100, 'manual_price' => 1500],
            ['base_price' => 0, 'outlet_discount_percent' => 20, 'manual_price' => 1500]] as $data) {
            $outlet = $this->project->calculationRows()->where('price_type', 'outlet')->first();
            $outlet->update($data + ['status' => 'approved', 'requires_review' => false]);
            foreach ($this->project->calculationRows()->get() as $row) {
                foreach (['approved', 'pending'] as $state) {
                    $this->assertSame($row->needsPricingAttention() === ($state === 'pending'),
                        PricingAttentionQuery::apply(PricingCalculationRow::whereKey($row->id), $state)->exists());
                }
            }
        }
    }

    public function test_retail_pending_includes_outlet_without_hiding_approved_retail(): void
    {
        $retail = $this->project->calculationRows()->where('price_type', 'retail')->whereHas('currency', fn ($q) => $q->where('code', 'HUF'))->first();
        $outlet = $this->project->calculationRows()->where('price_type', 'outlet')->whereHas('currency', fn ($q) => $q->where('code', 'HUF'))->first();
        $outlet->update(['source_retail_row_id' => $retail->id]);
        // The existing outlet column is activated by a configured discount parameter.
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => PricingParameter::TYPE_OUTLET_DISCOUNT,
            'scope_type' => 'global', 'price_list_id' => $outlet->price_list_id, 'value' => 20, 'active' => true]);
        $page = Livewire::withQueryParams(['workflowProject' => (string) $this->project->id, 'workflowArchive' => '0',
            'tableFilters' => ['workflow_state' => ['value' => 'pending']]])->test(PricingHufRetailCalculations::class);
        $page->assertCanSeeTableRecords([$retail])->filterTable('workflow_state', 'approved')->assertCanSeeTableRecords([$retail]);
    }

    public function test_foreign_and_wholesale_pages_filter_current_currency_by_workflow_state(): void
    {
        foreach ([PricingForeignRetailCalculations::class => 'retail', PricingWholesaleCalculations::class => 'wholesale'] as $class => $type) {
            $page = Livewire::test($class)->call('selectPricingProject', $this->project->id);
            if ($type === 'wholesale') {
                $page->call('selectWholesaleCurrency', 'EUR');
            }
            $row = $this->project->calculationRows()->where('price_type', $type)->whereHas('currency', fn ($q) => $q->where('code', 'EUR'))->first();
            $page->filterTable('workflow_state', $type === 'retail' ? 'approved' : 'pending')->assertCanSeeTableRecords([$row]);
            $page->filterTable('workflow_state', $type === 'retail' ? 'pending' : 'approved')->assertCanNotSeeTableRecords([$row]);
        }
    }

    public function test_distributor_filter_checks_target_not_the_wholesale_source(): void
    {
        $source = $this->project->calculationRows()->where('price_type', 'wholesale')->first();
        $target = $this->project->calculationRows()->where('price_type', 'distributor')->first();
        $target->priceList->update(['source_wholesale_price_list_id' => $source->price_list_id]);
        $target->refresh()->update(['source_wholesale_row_id' => $source->id, 'status' => 'approved', 'requires_review' => false]);
        $page = Livewire::test(PricingDistributorCalculations::class)->call('selectPricingProject', $this->project->id);
        $page->filterTable('workflow_state', 'approved')->assertCanSeeTableRecords([$source]);
        $page->filterTable('workflow_state', 'pending')->assertCanNotSeeTableRecords([$source]);
        $target->update(['requires_review' => true]);
        $page->call('$refresh')->assertCanSeeTableRecords([$source]);
    }

    public function test_overview_pending_checks_all_prices_not_just_its_base_cost_row(): void
    {
        $page = Livewire::test(PricingProductOverview::class)->call('selectPricingProject', $this->project->id);
        $costs = $this->project->calculationRows()->where('price_type', 'cost')->get();
        $page->filterTable('workflow_state', 'pending')->assertCanSeeTableRecords($costs);
        $page->filterTable('workflow_state', 'approved')->assertCountTableRecords(0);
    }

    public function test_unknown_workflow_status_is_rejected(): void
    {
        Livewire::test(PricingWorkflow::class)->call('selectPricingProject', $this->project->id)
            ->call('openPricingStep', 'cost', 'unexpected')->assertStatus(422);
    }

    public function test_archive_count_link_preserves_archive_scope_and_read_only_mode(): void
    {
        $this->project->update(['status' => 'closed']);
        $page = Livewire::test(PricingWorkflow::class)->call('setPricingArchive', true);
        $url = $page->instance()->workflowSteps['cost']['countUrls']['approved'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $target = Livewire::withQueryParams($query)->test(PricingCostCalculations::class)
            ->assertSet('pricingProjectId', $this->project->id)->assertSet('pricingArchive', true);
        $this->assertFalse($target->instance()->pricingWorkspaceIsEditable());
        $target->assertCanSeeTableRecords($this->project->calculationRows()->where('price_type', 'cost')->get());
        $query['workflowArchive'] = '0';
        Livewire::withQueryParams($query)->test(PricingCostCalculations::class)->assertStatus(422);
    }

    public function test_explicit_url_filters_survive_page_mount_and_total_link_removes_status_filter(): void
    {
        $page = Livewire::withQueryParams(['workflowProject' => (string) $this->project->id,
            'tableFilters' => ['workflow_state' => ['value' => 'pending'], 'requires_review' => ['value' => true]]])
            ->test(PricingCostCalculations::class);
        $this->assertTrue((bool) $page->instance()->getTableFilterState('requires_review')['value']);
        $this->assertSame('pending', $page->instance()->getTableFilterState('workflow_state')['value']);
        $page = Livewire::withQueryParams(['workflowProject' => (string) $this->project->id,
            'tableFilters' => ['workflow_state' => ['value' => '']]])->test(PricingCostCalculations::class);
        $this->assertNull($page->instance()->getTableFilterState('workflow_state')['value']);
        $page->assertCountTableRecords(2);
    }

    public function test_invalid_workflow_url_status_is_rejected_and_destination_rights_still_apply(): void
    {
        Livewire::withQueryParams(['tableFilters' => ['workflow_state' => ['value' => 'not-a-state']]])
            ->test(PricingCostCalculations::class)->assertStatus(422);
        $reader = User::factory()->create();
        $this->actingAs($reader);
        Livewire::withQueryParams(['workflowProject' => (string) $this->project->id,
            'tableFilters' => ['workflow_state' => ['value' => 'approved']]])
            ->test(PricingCostCalculations::class)->assertForbidden();
    }
}
