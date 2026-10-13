<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\AdminUiPreference;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\CostCalculationService;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\HufRetailCalculator;
use App\Services\Pricing\PricingRoundingService;
use App\Services\Pricing\RetailCalculationDependencyService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PDO;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HufRetailCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PricingCalculationRow $cost;

    private PricingParameter $multiplier;

    private PricingRoundingRule $rounding;

    private PriceList $list;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        $season = Season::create(['code' => 'S27', 'name' => 'Teszt szezon', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'B10024W27', 'name_hu' => 'Teszt táska', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt árprojekt', 'status' => 'active', 'active' => true]);
        $this->list = PriceList::create(['code' => 'RETAIL-HUF', 'name_hu' => 'HUF kisker', 'type' => 'retail',
            'currency_id' => $currency->id, 'active' => true]);
        $this->cost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id,
            'product_id' => $product->id, 'price_type' => 'cost', 'currency_id' => $currency->id,
            'calculated_price' => 460, 'calculation_snapshot' => ['test_source' => true]]);
        $this->multiplier = PricingParameter::create(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        $this->rounding = PricingRoundingRule::create(['pricing_project_id' => $this->project->id,
            'name' => 'HUF 990', 'price_type' => 'retail', 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($user);
    }

    public function test_example_is_saved_with_inputs_and_no_live_price_list_change(): void
    {
        $result = $this->calculate();
        $row = $this->retail();
        $this->assertSame(1, $result['created']);
        $this->assertSame('2162.0000', $row->unrounded_price);
        $this->assertSame('1990.0000', $row->calculated_price);
        $this->assertSame('1990.0000', $row->final_price);
        $this->assertSame('460.0000', $row->base_price);
        $this->assertSame('4.700000', $row->multiplier);
        $this->assertSame($this->cost->id, $row->calculation_snapshot['source_cost_row_id']);
        $this->assertTrue($row->calculation_snapshot['source_cost_snapshot']['test_source']);
        $this->assertSame($this->rounding->id, $row->pricing_rounding_rule_id);
        $this->assertSame(0, DB::table('price_list_items')->count());
    }

    public function test_effective_cost_and_specific_model_parameter_are_used(): void
    {
        $this->cost->update(['manual_price' => 600]);
        PricingParameter::create(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'retail_multiplier', 'scope_type' => 'product', 'product_id' => $this->cost->product_id,
            'price_list_id' => $this->list->id, 'currency_id' => $this->list->currency_id, 'value' => 5.5, 'active' => true]);
        $this->calculate();
        $row = $this->retail();
        $this->assertSame('600.0000', $row->base_price);
        $this->assertSame('5.500000', $row->multiplier);
        $this->assertSame('3300.0000', $row->unrounded_price);
        $this->assertSame('2990.0000', $row->calculated_price);
    }

    public function test_price_list_rounding_overrides_global_and_recalculation_does_not_duplicate_rows(): void
    {
        $specific = PricingRoundingRule::create(['pricing_project_id' => $this->project->id,
            'name' => 'Árlista 100', 'price_type' => 'retail', 'price_list_id' => $this->list->id,
            'round_to' => 100, 'mode' => 'up', 'adjustment' => -10, 'active' => true]);
        $this->calculate();
        $again = $this->calculate();
        $row = $this->retail();
        $this->assertSame('2190.0000', $row->calculated_price);
        $this->assertSame($specific->id, $row->pricing_rounding_rule_id);
        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['updated']);
        $this->assertSame(1, PricingCalculationRow::where('price_type', 'retail')->count());
    }

    public function test_approved_price_is_retained_until_user_decides(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($this->retail());
        $this->multiplier->update(['value' => 7]);
        $result = $this->calculate();
        $row = $this->retail();
        $this->assertSame('2990.0000', $row->calculated_price);
        $this->assertSame('1990.0000', $row->final_price);
        $this->assertSame('1990.0000', $row->retained_price);
        $this->assertNull($row->manual_price);
        $this->assertTrue($row->requires_review);
        $this->assertSame(1, $result['requires_review']);
        $this->assertSame(4.7, $row->calculation_snapshot['approval']['inputs']['multiplier']);
        $review->approveCurrentPrice($row);
        $this->assertFalse($this->retail()->requires_review);
        $this->assertSame('1990.0000', $this->retail()->final_price);
        $review->acceptCalculatedPrice($this->retail());
        $this->assertSame('2990.0000', $this->retail()->final_price);
        $this->assertNull($this->retail()->retained_price);
        $this->assertSame('approved', $this->retail()->status);
        $this->assertEquals(7, $this->retail()->calculation_snapshot['approval']['inputs']['multiplier']);
    }

    public function test_manual_price_survives_recalculation_and_can_be_replaced_explicitly(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->setManualPrice($this->retail(), 2490);
        $this->cost->update(['calculated_price' => 1000]);
        $this->calculate();
        $row = $this->retail();
        $this->assertSame('2490.0000', $row->manual_price);
        $this->assertSame('2490.0000', $row->final_price);
        $this->assertSame('4990.0000', $row->calculated_price);
        $this->assertTrue($row->requires_review);
        $review->acceptCalculatedPrice($row);
        $this->assertNull($this->retail()->manual_price);
        $this->assertFalse($this->retail()->requires_review);
    }

    public function test_missing_multiplier_and_ambiguous_rounding_are_logged(): void
    {
        $this->multiplier->update(['active' => false]);
        $result = $this->calculate();
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('Kisker szorzó', PricingCalculationError::where('run_id', $result['run_id'])->firstOrFail()->message);
        $this->multiplier->update(['active' => true]);
        $duplicate = $this->rounding->replicate();
        $duplicate->save();
        $result = $this->calculate();
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('azonos szintű', $result['errors'][0]);
    }

    public function test_wrong_currency_price_list_is_rejected(): void
    {
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euró', 'name_hu' => 'Euró', 'active' => true]);
        $this->list->update(['currency_id' => $eur->id]);
        $this->expectException(DomainException::class);
        $this->calculate();
    }

    public function test_same_list_can_be_used_in_two_season_projects_without_mixing_calculations(): void
    {
        $this->calculate();
        $season = Season::create(['code' => 'S28', 'name' => 'Második szezon', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Második árprojekt', 'status' => 'active', 'active' => true]);
        $product = $this->cost->product->replicate();
        $product->season_id = $season->id;
        $product->model_code = 'B10024W28';
        $product->save();
        $cost = $this->cost->replicate();
        $cost->pricing_project_id = $project->id;
        $cost->product_id = $product->id;
        $cost->calculated_price = 1000;
        $cost->save();
        foreach ([$this->multiplier, $this->rounding] as $input) {
            $copy = $input->replicate();
            $copy->pricing_project_id = $project->id;
            $copy->save();
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)
            ->callAction('calculateHufRetail', ['pricing_project_id' => $project->id, 'price_list_id' => $this->list->id])
            ->assertHasNoErrors();

        $this->assertSame('1990.0000', $this->retail()->final_price);
        $this->assertSame('4990.0000', PricingCalculationRow::where('pricing_project_id', $project->id)
            ->where('price_type', 'retail')->where('price_list_id', $this->list->id)->firstOrFail()->final_price);
        $this->assertSame(1, PriceList::count());
    }

    public function test_read_only_user_can_view_but_cannot_change_prices(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        $reader->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:PricingCalculationRow', 'guard_name' => 'web']));
        $reader->givePermissionTo(Permission::findOrCreate('View:PricingHufRetailCalculations', 'web'));
        $this->actingAs($reader);
        $this->assertTrue(PricingHufRetailCalculations::canAccess());
        $this->expectException(AuthorizationException::class);
        app(RetailPriceReviewService::class)->setManualPrice($this->retail(), 1);
    }

    public function test_layout_preferences_are_separate_and_old_cost_preferences_are_kept(): void
    {
        $costPage = new PricingCostCalculations;
        $retailPage = new PricingHufRetailCalculations;
        $costPage->savePricingColumnWidths(['model-code' => 120]);
        $retailPage->savePricingColumnWidths(['model-code' => 240]);
        $this->assertSame(120, AdminUiPreference::where('preference_key', 'pricing_cost_calculation_column_widths')->firstOrFail()->value['model-code']);
        $this->assertSame(240, AdminUiPreference::where('preference_key', 'pricing_huf_retail_calculation_column_widths')->firstOrFail()->value['model-code']);
        $this->assertNotSame($costPage->pricingColumnStorageKey(), $retailPage->pricingColumnStorageKey());
    }

    public function test_retail_editor_role_can_edit_and_approve_without_cost_parameter_permissions(): void
    {
        $this->calculate();
        $editor = User::factory()->create();
        $role = Role::findOrCreate('retail_editor', 'web');
        $role->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow',
            'Update:PricingCalculationRow',
        ]));
        $editor->assignRole($role);
        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful()
            ->assertActionHidden('calculateHufRetail')
            ->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '2490')
            ->callTableAction('approveRetail', $this->retail(), ['approval_status' => PricingCalculationRow::STATUS_APPROVED])
            ->assertHasNoErrors();
        $this->assertSame('2490.0000', $this->retail()->final_price);
        $this->assertSame(PricingCalculationRow::STATUS_MODIFIED_APPROVED, $this->retail()->status);
        $this->assertSame($editor->id, $this->retail()->approved_by);
        $this->assertFalse($editor->can('Update:PricingParameter'));
    }

    private function calculate(): array
    {
        return app(HufRetailCalculationService::class)->recalculate($this->project, $this->list);
    }

    public function test_page_and_snapshot_can_be_rendered_with_retail_inputs(): void
    {
        $this->calculate();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)
            ->assertSuccessful()->assertSee('HUF bruttó kisker árak')->assertSee('B10024W27')
            ->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '2490')
            ->assertHasNoErrors();
        $this->assertSame('2490.0000', $this->retail()->final_price);
        $html = view('filament.pricing.retail-calculation-details', ['record' => $this->retail()])->render();
        $this->assertStringContainsString('Kerekítés előtti bruttó kisker ár', $html);
        $this->assertStringContainsString('2 162,0000', $html);
        $this->assertStringContainsString('1 990,0000', $html);
    }

    public function test_review_column_is_red_when_review_is_required_and_green_otherwise(): void
    {
        $this->calculate();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful();
        $column = $page->instance()->getTable()->getColumn('requires_review')->record($this->retail());
        $this->assertFalse($column->getState());
        $this->assertSame('success', $column->getColor($column->getState()));

        $row = $this->retail();
        $row->update(['requires_review' => true]);
        $column->record($row)->clearCachedState();
        $this->assertTrue($column->getState());
        $this->assertSame('danger', $column->getColor($column->getState()));
        $this->assertSame('1990.0000', $row->fresh()->final_price);
    }

    public function test_manual_price_refreshes_multiplier_and_colored_direction_without_changing_the_formula(): void
    {
        $this->calculate();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertEqualsWithDelta(1990 / 460, $this->retail()->effective_multiplier, 0.0000001);
        $originalSnapshot = $this->retail()->calculation_snapshot;

        foreach ([2490 => ['up', 'success'], 1490 => ['down', 'danger'], 1990 => [null, null]] as $price => [$direction, $color]) {
            $page->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, (string) $price)
                ->assertHasNoErrors();
            $row = $this->retail();
            $this->assertEqualsWithDelta($price / 460, $row->effective_multiplier, 0.0000001);
            $this->assertEqualsWithDelta($price / 460, $page->instance()->averageRetailMultiplier, 0.0000001);
            $this->assertSame($direction, $row->finalPriceDifferenceDirection());
            $this->assertSame('4.700000', $row->multiplier);
            $this->assertSame($originalSnapshot, $row->calculation_snapshot);
            $this->assertSame('4.700000', $this->multiplier->fresh()->value);
            $column = $page->instance()->getTable()->getColumn('multiplier')->record($row);
            $this->assertSame($color, $column->getColor($row->effective_multiplier));
            $this->assertSame($color, $column->getIconColor($row->effective_multiplier));
            $this->assertSame($direction === null ? null : 'heroicon-o-arrow-'.$direction, $column->getIcon($row->effective_multiplier));
            $this->assertStringContainsString('pricing-retail-manual-'.($direction ?? 'equal'), $page->html());
        }

        $page->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, null)->assertHasNoErrors();
        $this->assertEqualsWithDelta(1990 / 460, $this->retail()->effective_multiplier, 0.0000001);
        $this->assertNull($this->retail()->finalPriceDifferenceDirection());
        $this->assertSame('1990.0000', $this->retail()->final_price);
    }

    public function test_effective_multiplier_can_be_sorted_filtered_and_exported(): void
    {
        $this->calculate();
        app(RetailPriceReviewService::class)->setManualPrice($this->retail(), 2490);
        $other = $this->retail()->replicate();
        $other->color_id = Color::create(['product_id' => $this->cost->product_id, 'code' => 'BR', 'name_hu' => 'Barna', 'active' => true])->id;
        $other->manual_price = 1490;
        $other->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $page->sortTable('multiplier', 'asc')->assertCanSeeTableRecords([$other, $this->retail()], inOrder: true);
        $page->sortTable('multiplier', 'desc')->assertCanSeeTableRecords([$this->retail(), $other], inOrder: true);
        $page->searchTableColumns(['multiplier' => '5,413043'])
            ->assertCanSeeTableRecords([$this->retail()])->assertCanNotSeeTableRecords([$other]);

        $column = $page->instance()->getTable()->getColumn('multiplier');
        $export = new AdminTableExport(PricingCalculationRow::query(), [$column]);
        $this->assertSame(['Kisker szorzó'], $export->headings());
        $this->assertEqualsWithDelta(2490 / 460, $export->map($this->retail())[0], 0.0000001);
        $original = $page->instance()->getTable()->getColumn('calculation_multiplier');
        $this->assertSame(4.7, (new AdminTableExport(PricingCalculationRow::query(), [$original]))->map($this->retail())[0]);
    }

    public function test_retained_price_uses_current_cost_for_its_multiplier_and_accepting_calculation_resets_it(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($this->retail());
        $this->cost->update(['calculated_price' => 1000]);
        $this->calculate();
        $row = $this->retail();
        $this->assertSame('1990.0000', $row->retained_price);
        $this->assertSame(1.99, $row->effective_multiplier);
        $this->assertSame('down', $row->finalPriceDifferenceDirection());
        $review->acceptCalculatedPrice($row);
        $this->assertSame(4.99, $this->retail()->effective_multiplier);
        $this->assertNull($this->retail()->finalPriceDifferenceDirection());
    }

    public function test_effective_multiplier_handles_missing_or_zero_cost_without_division_by_zero(): void
    {
        $this->calculate();
        foreach ([null, 0, -1] as $base) {
            $row = $this->retail();
            $row->update(['manual_price' => 2490, 'base_price' => $base]);
            $this->assertNull($row->effective_multiplier);
            $this->assertNull(PricingCalculationRow::whereKey($row->id)
                ->selectRaw(PricingCalculationRow::EFFECTIVE_MULTIPLIER_SQL.' AS sql_multiplier')->firstOrFail()->sql_multiplier);
        }
    }

    public function test_rounding_boundary_and_direction_are_respected(): void
    {
        $service = app(PricingRoundingService::class);
        $this->assertSame(5990.0, $service->apply(6499.99, $this->rounding));
        $this->assertSame(6990.0, $service->apply(6500, $this->rounding));
        $this->rounding->mode = PricingRoundingRule::MODE_DOWN;
        $this->assertSame(5990.0, $service->apply(6500, $this->rounding));
        $this->rounding->mode = PricingRoundingRule::MODE_UP;
        $this->assertSame(6990.0, $service->apply(6499.99, $this->rounding));
    }

    private function retail(): PricingCalculationRow
    {
        return PricingCalculationRow::where('pricing_project_id', $this->project->id)->where('price_type', 'retail')->firstOrFail();
    }

    public function test_each_price_list_uses_its_own_parameter_and_colors_have_separate_rows(): void
    {
        $otherList = PriceList::create(['code' => 'HUF-OTHER', 'name_hu' => 'Másik kisker', 'type' => 'retail',
            'currency_id' => $this->list->currency_id, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id,
            'parameter_type' => 'retail_multiplier', 'scope_type' => 'global',
            'price_list_id' => $this->list->id, 'value' => 7, 'active' => true]);
        $color = Color::create(['product_id' => $this->cost->product_id, 'code' => 'BR', 'name_hu' => 'Barna', 'active' => true]);
        $colorCost = $this->cost->replicate();
        $colorCost->color_id = $color->id;
        $colorCost->calculated_price = 700;
        $colorCost->save();
        $result = app(HufRetailCalculationService::class)->recalculate($this->project);
        $this->assertSame(4, $result['created']);
        $this->assertSame(2, PricingCalculationRow::where('price_type', 'retail')->where('color_id', $color->id)->count());
        $this->assertSame('2990.0000', PricingCalculationRow::where('price_type', 'retail')->where('price_list_id', $this->list->id)->whereNull('color_id')->firstOrFail()->calculated_price);
        $this->assertSame('1990.0000', PricingCalculationRow::where('price_type', 'retail')->where('price_list_id', $otherList->id)->whereNull('color_id')->firstOrFail()->calculated_price);
    }

    public function test_calculation_action_runs_from_the_workspace(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)
            ->callAction('calculateHufRetail', ['pricing_project_id' => $this->project->id, 'price_list_id' => $this->list->id])
            ->assertHasNoErrors();
        $this->assertSame('1990.0000', $this->retail()->calculated_price);
    }

    public function test_new_migration_can_be_reverted_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_12_130000_add_retail_inputs_to_pricing_calculation_rows.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('pricing_calculation_rows', 'retained_price'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('pricing_calculation_rows', [
            'base_price', 'multiplier', 'unrounded_price', 'pricing_rounding_rule_id', 'retained_price', 'requires_review',
        ]));
    }

    public function test_review_and_second_third_approval_rounds_preserve_prices(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $row = $review->approveCurrentPrice($this->retail(), PricingCalculationRow::STATUS_REVIEWED);
        $this->assertNotNull($row->reviewed_at);
        $this->assertNull($row->approved_at);
        foreach ([PricingCalculationRow::STATUS_APPROVED_ROUND_2, PricingCalculationRow::STATUS_APPROVED_ROUND_3] as $status) {
            $row = $review->approveCurrentPrice($this->retail(), $status);
            $this->assertSame($status, $row->status);
            $this->assertSame('1990.0000', $row->final_price);
            $this->assertNotNull($row->approved_by);
        }
    }

    public function test_cost_change_immediately_marks_approved_manual_price_and_rounding_does_not_hide_it(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $approved = $review->approveCurrentPrice($review->setManualPrice($this->retail(), 2490));
        $approval = $approved->calculation_snapshot['approval'];
        $this->cost->update(['shipping_cost_percent' => 9, 'calculated_price' => 470]);

        $row = $this->retail();
        $this->assertTrue($row->requires_review);
        $this->assertSame('modified_approved', $row->status);
        $this->assertSame('2490.0000', $row->final_price);
        $this->assertSame($approval, $row->calculation_snapshot['approval']);
        $this->assertSame('460.0000', $row->base_price);

        $this->calculate();
        $row = $this->retail();
        $this->assertSame('470.0000', $row->base_price);
        $this->assertSame('1990.0000', $row->calculated_price);
        $this->assertTrue($row->requires_review);
        $this->assertSame('2490.0000', $row->manual_price);
        $this->assertSame($approval, $row->calculation_snapshot['approval']);
        $review->approveCurrentPrice($row);
        $this->calculate();
        $this->assertFalse($this->retail()->requires_review);
        $this->assertSame('2490.0000', $this->retail()->final_price);
    }

    public function test_unchanged_cost_values_and_snapshot_timestamps_do_not_require_review(): void
    {
        $this->calculate();
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->retail());
        $this->cost->update(['calculated_price' => 460, 'candidate_count' => 2,
            'calculation_snapshot' => ['test_source' => true, 'calculated_at' => now()->addMinute()->toISOString()]]);
        $this->rounding->update(['name' => 'Átnevezett, változatlan szabály']);
        $this->assertFalse($this->retail()->requires_review);
        $this->calculate();
        $this->assertFalse($this->retail()->requires_review);
    }

    public function test_only_same_project_product_color_retail_rows_are_invalidated_across_lists(): void
    {
        $colorCost = $this->cost->replicate();
        $colorCost->color_id = Color::create(['product_id' => $this->cost->product_id, 'code' => 'BR', 'name_hu' => 'Barna', 'active' => true])->id;
        $colorCost->save();
        $product = $this->cost->product->replicate();
        $product->model_code = 'UNRELATED';
        $product->save();
        $otherCost = $this->cost->replicate();
        $otherCost->product_id = $product->id;
        $otherCost->save();
        $otherProject = $this->project->replicate();
        $otherProject->save();
        foreach ([$this->multiplier, $this->rounding] as $input) {
            $copy = $input->replicate();
            $copy->pricing_project_id = $otherProject->id;
            $copy->save();
        }
        $projectCost = $this->cost->replicate();
        $projectCost->pricing_project_id = $otherProject->id;
        $projectCost->save();
        PriceList::create(['code' => 'HUF-SECOND', 'name_hu' => 'Második árlista', 'type' => 'retail',
            'currency_id' => $this->list->currency_id, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project);
        app(HufRetailCalculationService::class)->recalculate($otherProject, $this->list);

        $this->cost->update(['calculated_price' => 470]);
        $this->assertSame(2, PricingCalculationRow::where('price_type', 'retail')->where('requires_review', true)->count());
        $this->assertFalse(PricingCalculationRow::where('price_type', 'retail')
            ->where('color_id', $colorCost->color_id)->firstOrFail()->requires_review);
        $this->assertFalse(PricingCalculationRow::where('price_type', 'retail')
            ->where('product_id', $product->id)->firstOrFail()->requires_review);
        $this->assertFalse(PricingCalculationRow::where('price_type', 'retail')
            ->where('pricing_project_id', $otherProject->id)->firstOrFail()->requires_review);
        $this->calculate();
        $this->assertFalse($this->retail()->requires_review); // No manual price/approval: recalculation resolves it.
    }

    public function test_reviewed_and_all_approved_statuses_preserve_prices_when_inputs_change(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        foreach ([PricingCalculationRow::STATUS_REVIEWED, PricingCalculationRow::STATUS_APPROVED,
            PricingCalculationRow::STATUS_APPROVED_ROUND_2, PricingCalculationRow::STATUS_APPROVED_ROUND_3] as $index => $status) {
            $approved = $review->approveCurrentPrice($this->retail(), $status);
            $this->cost->update(['calculated_price' => 470 + $index * 10]);
            $this->assertTrue($this->retail()->requires_review);
            $this->calculate();
            $this->assertTrue($this->retail()->requires_review);
            $this->assertSame($approved->status, $this->retail()->status);
            $this->assertSame('1990.0000', $this->retail()->final_price);
            $this->assertSame('1990.0000', $this->retail()->retained_price);
        }
    }

    public function test_multiplier_and_rounding_changes_require_review_even_if_price_stays_the_same(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($this->retail());
        $this->multiplier->update(['value' => 4.8]);
        $this->calculate();
        $this->assertSame('1990.0000', $this->retail()->calculated_price);
        $this->assertTrue($this->retail()->requires_review);
        $review->approveCurrentPrice($this->retail());
        $this->rounding->update(['mode' => 'down']);
        $this->calculate();
        $this->assertSame('1990.0000', $this->retail()->calculated_price);
        $this->assertTrue($this->retail()->requires_review);
    }

    public function test_stale_calculation_cannot_be_approved_accepted_or_cleared_by_a_manual_edit(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($review->setManualPrice($this->retail(), 2490));
        $this->cost->update(['calculated_price' => 470]);
        foreach (['approve', 'accept', 'edit'] as $operation) {
            try {
                match ($operation) {
                    'approve' => $review->approveCurrentPrice($this->retail()),
                    'accept' => $review->acceptCalculatedPrice($this->retail()),
                    'edit' => $review->setManualPrice($this->retail(), 2590),
                };
                $this->fail('An obsolete calculation must not be approved or edited.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('újraszámítását', $exception->getMessage());
            }
            $this->assertTrue($this->retail()->requires_review);
            $this->assertSame('2490.0000', $this->retail()->final_price);
            $this->assertSame('modified_approved', $this->retail()->status);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)
            ->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '2590')
            ->assertNotified('A kisker kalkuláció nem aktuális');
        Livewire::test(PricingHufRetailCalculations::class)
            ->callTableAction('approveRetail', $this->retail(), ['approval_status' => PricingCalculationRow::STATUS_APPROVED])
            ->assertNotified('A kisker kalkuláció nem aktuális');
        Livewire::test(PricingHufRetailCalculations::class)
            ->callTableAction('acceptCalculatedRetail', $this->retail())
            ->assertNotified('A kisker kalkuláció nem aktuális');
        $this->assertSame('2490.0000', $this->retail()->manual_price);
        $this->assertTrue($this->retail()->requires_review);
    }

    public function test_changed_shipping_input_is_reviewed_even_with_a_fixed_manual_cost(): void
    {
        $this->cost->update(['manual_price' => 460, 'shipping_cost_percent' => 8]);
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($review->setManualPrice($this->retail(), 2490));
        $this->cost->update(['shipping_cost_percent' => 9, 'calculated_price' => 470]);
        $this->assertSame('460.0000', $this->cost->fresh()->final_price);
        $this->assertTrue($this->retail()->requires_review);
        $this->calculate();
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame('1990.0000', $this->retail()->calculated_price);
        $this->assertSame('2490.0000', $this->retail()->final_price);
        $this->assertSame('8.0000', $this->retail()->calculation_snapshot['approval']['source_cost_inputs']['shipping_cost_percent']);
        $review->approveCurrentPrice($this->retail());
        $this->calculate();
        $this->assertFalse($this->retail()->requires_review);
        $this->assertSame('9.0000', $this->retail()->calculation_snapshot['approval']['source_cost_inputs']['shipping_cost_percent']);
    }

    public function test_prior_silent_recalculation_is_detected_using_the_approval_baseline(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $row = $review->approveCurrentPrice($review->setManualPrice($this->retail(), 2490));
        $approval = $row->calculation_snapshot['approval'];
        unset($approval['source_cost_inputs']); // Snapshot saved by the older release.
        $this->cost->updateQuietly(['calculated_price' => 470, 'final_price' => 470]);
        $calculation = app(HufRetailCalculator::class)->calculate($this->project, $this->cost->fresh(), $this->list);
        $row->fill(collect($calculation)->except('snapshot')->all());
        $row->calculation_snapshot = $calculation['snapshot'] + ['approval' => $approval];
        $row->saveQuietly();
        $this->assertFalse($this->retail()->requires_review);

        $this->calculate();
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame('2490.0000', $this->retail()->final_price);
        $this->assertSame($approval, $this->retail()->calculation_snapshot['approval']);
    }

    public function test_cost_change_and_dependency_flags_roll_back_together(): void
    {
        $this->calculate();
        try {
            DB::transaction(function (): void {
                $this->cost->update(['calculated_price' => 470]);
                $this->assertTrue($this->retail()->requires_review);
                throw new \RuntimeException('Abort test transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Abort test transaction', $exception->getMessage());
        }
        $this->assertSame('460.0000', $this->cost->fresh()->final_price);
        $this->assertFalse($this->retail()->requires_review);
    }

    public function test_real_shipping_parameter_edit_and_cost_recalculation_mark_existing_manual_approval(): void
    {
        $supplier = Supplier::create(['erp_partner_code' => 'SUP-TEST', 'name' => 'Teszt beszállító', 'active' => true]);
        ProductPurchasePrice::create(['product_id' => $this->cost->product_id, 'supplier_id' => $supplier->supplier_id,
            'currency_id' => $this->cost->currency_id, 'purchase_price' => 460, 'active' => true]);
        app(CostCalculationService::class)->recalculate($this->project);
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($review->setManualPrice($this->retail(), 2490));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_SHIPPING_COST_PERCENT, 9)
            ->set('pendingParameterScope', PricingParameter::SCOPE_PRODUCT)
            ->call('confirmPendingParameterAdjustment')
            ->assertHasNoErrors();

        $this->assertSame('9.0000', $this->cost->fresh()->shipping_cost_percent);
        $this->assertSame('501.4000', $this->cost->fresh()->final_price);
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame('2490.0000', $this->retail()->final_price);
        $this->assertSame('modified_approved', $this->retail()->status);
        $this->calculate();
        $this->assertSame('1990.0000', $this->retail()->calculated_price);
        $this->assertTrue($this->retail()->requires_review);
    }

    public function test_actual_multiplier_includes_rounding_without_a_manual_override(): void
    {
        $this->calculate();
        $row = $this->retail();
        $this->assertNull($row->manual_price);
        $this->assertEqualsWithDelta(1990 / 460, $row->effective_multiplier, 0.0000001);
        $this->assertSame('4.700000', $row->multiplier);
        $this->assertEqualsWithDelta($row->effective_multiplier, (float) PricingCalculationRow::whereKey($row->id)
            ->selectRaw(PricingCalculationRow::EFFECTIVE_MULTIPLIER_SQL.' AS sql_multiplier')->firstOrFail()->sql_multiplier, 0.0000001);
    }

    public function test_negotiation_target_is_capped_and_rounded_down_in_purchase_currency(): void
    {
        $row = new PricingCalculationRow(['base_price' => 100, 'multiplier' => 2,
            'purchase_price' => 10, 'calculated_price' => 199]);
        $this->assertSame('10.0000', $row->target_purchase_price);
        $this->assertFalse($row->needsPurchasePriceNegotiation());
        $row->manual_price = 150;
        $this->assertSame('7.5000', $row->target_purchase_price);
        $this->assertTrue($row->needsPurchasePriceNegotiation());
        $row->manual_price = 250;
        $this->assertSame('10.0000', $row->target_purchase_price);
        $this->assertFalse($row->needsPurchasePriceNegotiation());
        $row->manual_price = 199;
        $this->assertSame('9.9500', $row->target_purchase_price); // Target is the configured multiplier, not the rounded old price.
        $row->manual_price = null;
        $row->retained_price = 150;
        $this->assertSame('7.5000', $row->target_purchase_price);
        $row->retained_price = null;
        $this->assertSame('10.0000', $row->target_purchase_price);

        $row->base_price = 460;
        $row->multiplier = 4.7;
        $row->purchase_price = 8.8;
        $row->manual_price = 1500;
        $this->assertSame('6.1054', $row->target_purchase_price);
        $this->assertGreaterThanOrEqual(4.7, 1500 / (460 * (float) $row->target_purchase_price / 8.8));
        $row->base_price = 0;
        $this->assertNull($row->target_purchase_price);
        $row->base_price = 460;
        $row->multiplier = null;
        $this->assertNull($row->target_purchase_price);
        $row->multiplier = 4.7;
        $row->purchase_price = null;
        $this->assertNull($row->target_purchase_price);
    }

    public function test_supplier_columns_and_purchase_target_support_filter_sort_and_export(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'name_hu' => 'Dollár', 'active' => true]);
        $supplier = Supplier::create(['erp_partner_code' => 'SUP-PRICE', 'name' => 'Test Supplier Name', 'active' => true]);
        $this->cost->update(['purchase_price' => 8.8, 'purchase_currency_id' => $usd->id, 'supplier_id' => $supplier->supplier_id]);
        $this->calculate();
        app(RetailPriceReviewService::class)->setManualPrice($this->retail(), 1500);
        $lower = $this->retail();
        $higher = $lower->replicate();
        $higher->color_id = Color::create(['product_id' => $this->cost->product_id, 'code' => 'BR', 'name_hu' => 'Barna', 'active' => true])->id;
        $higher->manual_price = 2490;
        $higher->save();
        $this->assertSame('6.1054', $lower->target_purchase_price);
        $this->assertEqualsWithDelta(6.1054, (float) PricingCalculationRow::whereKey($lower->id)
            ->selectRaw(PricingCalculationRow::targetPurchasePriceSql().' AS sql_target')->firstOrFail()->sql_target, 0.0000001);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $columns = $page->instance()->getDefaultTableColumnState();
        foreach ($columns as &$column) {
            if (in_array($column['name'], ['supplier.erp_partner_code', 'supplier.name', 'purchaseCurrency.code'], true)) {
                $column['isToggled'] = true;
            }
        }
        unset($column);
        $page->call('applyTableColumnManager', $columns)
            ->assertSee('SUP-PRICE')->assertSee('Test Supplier Name')->assertSee('6,1054 USD');
        $page->sortTable('target_purchase_price', 'asc')->assertCanSeeTableRecords([$lower, $higher], inOrder: true);
        $page->sortTable('target_purchase_price', 'desc')->assertCanSeeTableRecords([$higher, $lower], inOrder: true);
        $page->searchTableColumns(['target_purchase_price' => '6,1054'])
            ->assertCanSeeTableRecords([$lower])->assertCanNotSeeTableRecords([$higher]);
        $page->searchTableColumns(['supplier.erp_partner_code' => 'SUP-PRICE', 'supplier.name' => 'Test Supplier'])
            ->assertCanSeeTableRecords([$lower, $higher]);
        $target = $page->instance()->getTable()->getColumn('target_purchase_price')->record($lower);
        $this->assertSame('danger', $target->getColor($lower->target_purchase_price));
        $target->record($higher);
        $this->assertNull($target->getColor($higher->target_purchase_price));
        $table = $page->instance()->getTable();
        $export = new AdminTableExport(PricingCalculationRow::query(), [
            $table->getColumn('supplier.erp_partner_code'), $table->getColumn('supplier.name'), $table->getColumn('target_purchase_price'),
        ]);
        $this->assertSame(['SUP-PRICE', 'Test Supplier Name', 6.1054], $export->map($lower));
    }

    public function test_average_multiplier_is_arithmetic_and_follows_filters_and_search(): void
    {
        $this->calculate();
        $first = $this->retail();
        $first->update(['base_price' => 100, 'calculated_price' => 200]);
        $product = $this->cost->product->replicate();
        $product->model_code = 'SECOND-MODEL';
        $product->save();
        $second = $first->replicate();
        $second->product_id = $product->id;
        $second->base_price = 300;
        $second->calculated_price = 1200;
        $second->save();
        $otherList = PriceList::create(['code' => 'OTHER-AVG', 'name_hu' => 'Másik átlag árlista', 'type' => 'retail',
            'currency_id' => $this->list->currency_id, 'active' => true]);
        $third = $first->replicate();
        $third->price_list_id = $otherList->id;
        $third->calculated_price = 1000;
        $third->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertEqualsWithDelta(16 / 3, $page->instance()->averageRetailMultiplier, 0.0000001);
        $page->filterTable('price_list_id', $this->list->id);
        $this->assertSame(3.0, $page->instance()->averageRetailMultiplier);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 3"', false);
        $this->assertNotSame(3.5, $page->instance()->averageRetailMultiplier); // Sum(price) / sum(cost) would be weighted.
        $page->searchTableColumns(['product.model_code' => 'SECOND-MODEL']);
        $this->assertSame(4.0, $page->instance()->averageRetailMultiplier);
        $page->searchTableColumns(['product.model_code' => 'NO-SUCH-PRODUCT']);
        $this->assertNull($page->instance()->averageRetailMultiplier);
        $page->assertSee('data-pricing-average-multiplier="Átlag: -"', false);
    }

    public function test_average_includes_rows_outside_the_current_page_and_skips_invalid_costs(): void
    {
        $this->calculate();
        $first = $this->retail();
        $first->update(['base_price' => 100, 'calculated_price' => 200]);
        for ($index = 1; $index <= 50; $index++) {
            $copy = $first->replicate();
            $copy->color_id = Color::create(['product_id' => $this->cost->product_id, 'code' => 'C'.$index, 'name_hu' => 'Szín '.$index, 'active' => true])->id;
            $copy->calculated_price = $index === 50 ? 400 : 200;
            $copy->save();
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class)->set('tableRecordsPerPage', 50);
        $this->assertSame(50, $page->instance()->getTableRecords()->count());
        $this->assertEqualsWithDelta(104 / 51, $page->instance()->averageRetailMultiplier, 0.0000001);
        $first->update(['base_price' => 0]);
        $page->call('$refresh');
        $this->assertEqualsWithDelta(102 / 50, $page->instance()->averageRetailMultiplier, 0.0000001);
    }

    public function test_bulk_approval_preserves_manual_prices_and_only_changes_selected_rows(): void
    {
        $this->addColorCost('BR');
        $this->addColorCost('BK');
        $this->calculate();
        $rows = PricingCalculationRow::where('price_type', 'retail')->orderBy('id')->get();
        app(RetailPriceReviewService::class)->setManualPrice($rows[0], 1490);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertTrue($page->instance()->getTable()->isSelectionEnabled());
        $page->callTableBulkAction('approveRetailBulk', [$rows[0], $rows[1]], [
            'approval_status' => PricingCalculationRow::STATUS_APPROVED,
        ])->assertHasNoErrors()->assertNotified('Tömeges jóváhagyás befejezve')
            ->assertDispatched('deselectAllTableRecords');
        $this->assertSame(PricingCalculationRow::STATUS_MODIFIED_APPROVED, $rows[0]->fresh()->status);
        $this->assertSame('1490.0000', $rows[0]->fresh()->manual_price);
        $this->assertSame('1490.0000', $rows[0]->fresh()->final_price);
        $this->assertSame(PricingCalculationRow::STATUS_APPROVED, $rows[1]->fresh()->status);
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $rows[2]->fresh()->status);
        foreach ([$rows[0], $rows[1]] as $row) {
            $this->assertSame(auth()->id(), $row->fresh()->approved_by);
            $this->assertSame(auth()->id(), $row->fresh()->calculation_snapshot['approval']['user_id']);
        }
        $this->assertSame(0, DB::table('price_list_items')->count());
    }

    public function test_bulk_approval_supports_every_existing_review_level_and_deduplicates_rows(): void
    {
        $this->calculate();
        $service = app(RetailPriceReviewService::class);
        foreach ([PricingCalculationRow::STATUS_REVIEWED, PricingCalculationRow::STATUS_APPROVED,
            PricingCalculationRow::STATUS_APPROVED_ROUND_2, PricingCalculationRow::STATUS_APPROVED_ROUND_3] as $status) {
            $result = $service->approveCurrentPrices(collect([$this->retail(), $this->retail()]), $status);
            $this->assertSame(['approved' => 1, 'skipped' => 0, 'reasons' => []], $result);
            $this->assertSame($status, $this->retail()->status);
            $this->assertSame($status, $this->retail()->calculation_snapshot['approval']['status']);
            $this->assertSame($status === PricingCalculationRow::STATUS_REVIEWED ? null : auth()->id(), $this->retail()->approved_by);
        }
    }

    public function test_bulk_approval_skips_stale_calculations_and_reports_the_reason(): void
    {
        $colorCost = $this->addColorCost('BR');
        $this->calculate();
        $stale = $this->retail();
        app(RetailPriceReviewService::class)->setManualPrice($stale, 2490);
        $this->cost->update(['calculated_price' => 470]);
        $current = PricingCalculationRow::where('price_type', 'retail')->where('color_id', $colorCost->color_id)->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)
            ->callTableBulkAction('approveRetailBulk', [$stale, $current], ['approval_status' => PricingCalculationRow::STATUS_APPROVED])
            ->assertHasNoErrors();
        $notification = collect(session('filament.claimed_notifications'))->last();
        $this->assertSame('Tömeges jóváhagyás befejezve', $notification['title']);
        $this->assertSame('warning', $notification['status']);
        $this->assertStringContainsString('Sikeres: 1 sor. Kihagyott: 1 sor.', $notification['body']);
        $this->assertStringContainsString('újraszámítását', $notification['body']);
        $this->assertSame('2490.0000', $stale->fresh()->final_price);
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $stale->fresh()->status);
        $this->assertTrue($stale->fresh()->requires_review);
        $this->assertSame(PricingCalculationRow::STATUS_APPROVED, $current->fresh()->status);
    }

    public function test_bulk_approval_is_hidden_and_server_side_denied_for_read_only_users(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow',
        ]));
        $this->actingAs($reader);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertTableBulkActionHidden('approveRetailBulk');
        $this->assertFalse($page->instance()->getTable()->isSelectionEnabled());
        $page->mountTableBulkAction('approveRetailBulk', [$this->retail()])->assertTableBulkActionNotMounted('approveRetailBulk');
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $this->retail()->status);
        $this->expectException(AuthorizationException::class);
        app(RetailPriceReviewService::class)->approveCurrentPrices(collect([$this->retail()]));
    }

    public function test_bulk_approval_rechecks_each_rows_permission_and_project_state(): void
    {
        $colorCost = $this->addColorCost('BR');
        $this->calculate();
        $denied = $this->retail();
        $allowed = PricingCalculationRow::where('price_type', 'retail')->where('color_id', $colorCost->color_id)->firstOrFail();
        $editor = User::factory()->create();
        $editor->givePermissionTo(Permission::findOrCreate('Update:PricingCalculationRow', 'web'));
        $this->actingAs($editor);
        Gate::before(fn (User $user, string $ability, array $arguments) => $ability === 'update'
            && ($arguments[0] ?? null) instanceof PricingCalculationRow && $arguments[0]->id === $denied->id ? false : null);
        $service = app(RetailPriceReviewService::class);
        $result = $service->approveCurrentPrices(collect([$denied, $allowed, $this->cost]));
        $this->assertSame(1, $result['approved']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['reasons']['Nincs módosítási jogosultság.']);
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $denied->fresh()->status);
        $this->project->update(['status' => PricingProject::STATUS_CLOSED]);
        $result = $service->approveCurrentPrices(collect([$allowed]), PricingCalculationRow::STATUS_APPROVED_ROUND_2);
        $this->assertSame(0, $result['approved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(PricingCalculationRow::STATUS_APPROVED, $allowed->fresh()->status);
    }

    public function test_closed_or_inactive_project_rows_cannot_be_selected(): void
    {
        $this->calculate();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertTrue($page->instance()->getTable()->isRecordSelectable($this->retail()));
        $this->project->update(['status' => PricingProject::STATUS_CLOSED]);
        $this->assertFalse($page->instance()->getTable()->isRecordSelectable($this->retail()));
        $this->project->update(['status' => PricingProject::STATUS_ACTIVE, 'active' => false]);
        $this->assertFalse($page->instance()->getTable()->isRecordSelectable($this->retail()));
    }

    public function test_bulk_select_all_uses_all_filtered_pages_and_respects_deselected_rows(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $this->addColorCost('C'.$index);
        }
        $this->calculate();
        $excluded = $this->retail();
        $otherList = PriceList::create(['code' => 'OTHER-BULK', 'name_hu' => 'Másik árlista', 'type' => 'retail',
            'currency_id' => $this->list->currency_id, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $otherList);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class)->set('tableRecordsPerPage', 50)
            ->filterTable('price_list_id', $this->list->id);
        $this->assertSame(50, $page->instance()->getTableRecords()->count());
        $this->assertSame(52, $page->instance()->getAllSelectableTableRecordsCount());
        $page->set('isTrackingDeselectedTableRecords', true)
            ->set('deselectedTableRecords', [(string) $excluded->id])
            ->callTableBulkAction('approveRetailBulk', [], ['approval_status' => PricingCalculationRow::STATUS_APPROVED])
            ->assertNotified('Tömeges jóváhagyás befejezve');
        $this->assertSame(51, PricingCalculationRow::where('price_type', 'retail')->where('status', PricingCalculationRow::STATUS_APPROVED)->count());
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $excluded->fresh()->status);
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $otherList->id)->whereNot('status', PricingCalculationRow::STATUS_CALCULATED)->count());
    }

    public function test_bulk_query_cannot_approve_forged_cost_or_non_huf_row_ids(): void
    {
        $this->calculate();
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'name_hu' => 'Euró', 'active' => true]);
        $foreign = $this->retail()->replicate();
        $foreign->currency_id = $eur->id;
        $foreign->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PricingHufRetailCalculations::class)->callTableBulkAction('approveRetailBulk', [
            $this->cost, $foreign, $this->retail(),
        ], ['approval_status' => PricingCalculationRow::STATUS_APPROVED])->assertHasNoErrors();
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $this->cost->fresh()->status);
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $foreign->fresh()->status);
        $this->assertSame(PricingCalculationRow::STATUS_APPROVED, $this->retail()->status);
    }

    public function test_unexpected_bulk_failure_rolls_back_previously_approved_rows(): void
    {
        $this->addColorCost('BR');
        $this->calculate();
        $rows = PricingCalculationRow::where('price_type', 'retail')->orderBy('id')->get();
        $this->mock(RetailCalculationDependencyService::class, function ($mock) use ($rows): void {
            $mock->shouldReceive('assertCalculationCurrent')->once()->withArgs(fn ($row) => $row->id === $rows[0]->id);
            $mock->shouldReceive('assertCalculationCurrent')->once()->withArgs(fn ($row) => $row->id === $rows[1]->id)
                ->andThrow(new \RuntimeException('Unexpected database/workflow failure'));
        });
        try {
            app(RetailPriceReviewService::class)->approveCurrentPrices($rows);
            $this->fail('The unexpected error must not be silently skipped.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected database/workflow failure', $exception->getMessage());
        }
        foreach ($rows as $row) {
            $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $row->fresh()->status);
            $this->assertNull($row->fresh()->approved_by);
            $this->assertArrayNotHasKey('approval', $row->fresh()->calculation_snapshot);
        }
    }

    public function test_bulk_approval_rechecks_workspace_access_after_the_modal_was_opened(): void
    {
        $this->calculate();
        $editor = User::factory()->create();
        $editor->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'Update:PricingCalculationRow',
        ]));
        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->mountTableBulkAction('approveRetailBulk', [$this->retail()])->assertTableBulkActionMounted('approveRetailBulk');
        $editor->revokePermissionTo('View:PricingHufRetailCalculations');
        $page->callMountedTableBulkAction();
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $this->retail()->status);
        $this->assertNull($this->retail()->approved_by);
    }

    public function test_bulk_approval_rejects_an_invalid_status_without_writing_any_rows(): void
    {
        $this->calculate();
        try {
            app(RetailPriceReviewService::class)->approveCurrentPrices(collect([$this->retail()]), 'invalid');
            $this->fail('Invalid review levels must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Érvénytelen jóváhagyási státusz.', $exception->getMessage());
        }
        $this->assertSame(PricingCalculationRow::STATUS_CALCULATED, $this->retail()->status);
        $this->assertNull($this->retail()->approved_by);
    }

    private function addColorCost(string $code): PricingCalculationRow
    {
        $cost = $this->cost->replicate();
        $cost->color_id = Color::create(['product_id' => $this->cost->product_id, 'code' => $code, 'name_hu' => $code, 'active' => true])->id;
        $cost->save();

        return $cost;
    }
}
