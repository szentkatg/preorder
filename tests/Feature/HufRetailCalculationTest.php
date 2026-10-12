<?php

namespace Tests\Feature;

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
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\PricingRoundingService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'season_id' => $season->id, 'currency_id' => $currency->id, 'active' => true]);
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

    public function test_wrong_season_or_currency_price_list_is_rejected(): void
    {
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euró', 'name_hu' => 'Euró', 'active' => true]);
        $this->list->update(['currency_id' => $eur->id]);
        $this->expectException(DomainException::class);
        $this->calculate();
    }

    public function test_read_only_user_can_view_but_cannot_change_prices(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        $reader->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:PricingCalculationRow', 'guard_name' => 'web']));
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
}
