<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\ForeignRetailCalculationService;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignRetailCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PriceList $hufList;

    private PriceList $list;

    private PricingCalculationRow $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél 2027', 'active' => false]);
        $huf = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'TEST', 'name_hu' => 'Teszt', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'MODEL27', 'name_hu' => 'NACKW27', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Tél', 'status' => 'active', 'active' => false]);
        $this->hufList = PriceList::create(['code' => 'HU', 'name_hu' => 'Magyar kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id, 'currency_id' => $huf->id, 'price_type' => 'cost', 'calculated_price' => 460]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'HUF', 'price_type' => 'retail', 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->hufList);
        $this->source = PricingCalculationRow::where('price_type', 'retail')->firstOrFail();
        $this->list = $this->makeList('EUR', 400);
    }

    public function test_each_currency_uses_huf_final_price_and_its_own_rate_multiplier_and_rounding(): void
    {
        foreach (['EUR' => 400, 'CZK' => 16, 'RON' => 80, 'PLN' => 100] as $code => $rate) {
            $list = $code === 'EUR' ? $this->list : $this->makeList($code, $rate);
            $result = $this->calculate($list);
            $row = $this->retail($list);
            $this->assertSame(1, $result['created']);
            $this->assertEquals(round(1990 / $rate * 1.1) - 0.05, (float) $row->final_price);
            $this->assertSame($this->source->id, $row->source_retail_row_id);
            $this->assertSame('1990.0000', $row->source_retail_price);
            $this->assertEquals($rate, (float) $row->retail_exchange_rate);
            $this->assertSame($code, $row->calculation_snapshot['currency']);
            $this->assertSame(0, $this->calculate($list)['created']);
        }
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_manual_huf_final_price_is_used_not_the_calculated_huf_price(): void
    {
        app(RetailPriceReviewService::class)->setManualPrice($this->source, 9990);
        $this->calculate();
        $this->assertSame('9990.0000', $this->retail()->source_retail_price);
        $this->assertSame('26.9500', $this->retail()->final_price);
    }

    public function test_manual_and_approved_foreign_prices_are_preserved_after_rate_changes(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->setManualPrice($this->retail(), 4.95);
        $review->approveCurrentPrice($this->retail());
        ExchangeRate::where('currency_id', $this->list->currency_id)->update(['rate_to_huf' => 300]);
        $result = $this->calculate();
        $this->assertSame('4.9500', $this->retail()->final_price);
        $this->assertSame('6.9500', $this->retail()->calculated_price);
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame(1, $result['requires_review']);
        $review->acceptCalculatedPrice($this->retail());
        $this->assertSame('6.9500', $this->retail()->final_price);
        $this->assertFalse($this->retail()->requires_review);
    }

    public function test_approved_non_manual_foreign_price_is_retained_when_source_huf_changes(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->approveCurrentPrice($this->retail());
        $before = $this->retail()->final_price;
        $review->setManualPrice($this->source, 9990);
        $this->assertTrue($this->retail()->requires_review);
        $this->calculate();
        $this->assertSame($before, $this->retail()->final_price);
        $this->assertSame($before, $this->retail()->retained_price);
        $review->approveCurrentPrice($this->retail());
        $this->assertFalse($this->retail()->requires_review);
    }

    public function test_stale_foreign_calculation_cannot_be_approved_before_recalculation(): void
    {
        $this->calculate();
        ExchangeRate::where('currency_id', $this->list->currency_id)->update(['rate_to_huf' => 300]);
        $this->expectException(DomainException::class);
        app(RetailPriceReviewService::class)->approveCurrentPrice($this->retail());
    }

    public function test_missing_inputs_are_logged_without_creating_or_overwriting_prices(): void
    {
        foreach (['rate', 'parameter', 'rounding'] as $missing) {
            $list = $this->makeList(['rate' => 'CZK', 'parameter' => 'RON', 'rounding' => 'PLN'][$missing], 100);
            match ($missing) {
                'rate' => ExchangeRate::where('currency_id', $list->currency_id)->delete(),
                'parameter' => PricingParameter::where('price_list_id', $list->id)->delete(),
                'rounding' => PricingRoundingRule::where('price_list_id', $list->id)->delete(),
            };
            $this->assertSame(1, $this->calculate($list)['skipped']);
            $this->assertSame(0, PricingCalculationRow::where('price_list_id', $list->id)->count());
        }
        $this->assertDatabaseCount('pricing_calculation_errors', 3);
    }

    public function test_cost_change_invalidates_huf_and_foreign_rows_and_foreign_waits_for_huf_review(): void
    {
        $this->calculate();
        PricingCalculationRow::where('price_type', 'cost')->firstOrFail()->update(['manual_price' => 600]);
        $this->assertTrue($this->source->fresh()->requires_review);
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame(1, $this->calculate()['skipped']);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->hufList);
        $this->assertSame(1, $this->calculate()['updated']);
    }

    public function test_currency_tabs_share_project_selection_but_only_show_the_chosen_currency(): void
    {
        $this->calculate();
        $czk = $this->makeList('CZK', 16);
        $this->calculate($czk);
        $page = Livewire::test(PricingForeignRetailCalculations::class)->assertSet('pricingProjectId', $this->project->id)
            ->assertCanSeeTableRecords([$this->retail()])->assertCanNotSeeTableRecords([$this->retail($czk)])
            ->assertSee('Előző szezon kisker EUR')->assertActionVisible('calculateForeignRetail');
        $page->selectTableRecords([$this->retail()])->call('selectRetailCurrency', 'CZK')->assertSet('selectedTableRecords', [])
            ->assertCanSeeTableRecords([$this->retail($czk)])->assertCanNotSeeTableRecords([$this->retail()])
            ->assertSee('Előző szezon kisker CZK');
        Livewire::test(PricingForeignRetailCalculations::class)->assertSet('retailCurrency', 'CZK');
    }

    public function test_archive_prevents_foreign_recalculation_manual_edits_and_bulk_approval(): void
    {
        $this->calculate();
        $this->project->update(['status' => 'closed']);
        Livewire::test(PricingForeignRetailCalculations::class)->call('setPricingArchive', true)
            ->assertCanSeeTableRecords([$this->retail()])->assertActionHidden('calculateForeignRetail')
            ->assertTableBulkActionHidden('approveRetailBulk')
            ->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '10')->assertForbidden();
        $this->expectException(DomainException::class);
        $this->calculate();
    }

    public function test_foreign_workspace_requires_its_own_page_permission(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo(Permission::findOrCreate('ViewAny:PricingCalculationRow', 'web'));
        $this->actingAs($reader);
        $this->assertFalse(PricingForeignRetailCalculations::canAccess());
        $reader->givePermissionTo(Permission::findOrCreate('View:PricingForeignRetailCalculations', 'web'));
        $this->assertTrue(PricingForeignRetailCalculations::canAccess());
        Livewire::test(PricingForeignRetailCalculations::class)->assertActionHidden('calculateForeignRetail')
            ->assertTableBulkActionHidden('approveRetailBulk');
        $this->assertFalse(PricingHufRetailCalculations::canAccess());
    }

    public function test_huf_permission_does_not_grant_foreign_workspace_access(): void
    {
        $reader = User::factory()->create();
        foreach (['ViewAny:PricingCalculationRow', 'View:PricingHufRetailCalculations'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($reader);
        $this->assertTrue(PricingHufRetailCalculations::canAccess());
        $this->assertFalse(PricingForeignRetailCalculations::canAccess());
    }

    public function test_selected_currency_cannot_be_bypassed_with_a_forged_record_or_target_list(): void
    {
        $czk = $this->makeList('CZK', 16);
        $this->calculate($czk);
        Livewire::test(PricingForeignRetailCalculations::class)
            ->call('updateTableColumnState', 'manual_price', (string) $this->retail($czk)->id, '10');
        $this->assertNull($this->retail($czk)->manual_price);
        Livewire::test(PricingForeignRetailCalculations::class)
            ->callAction('calculateForeignRetail', ['source_price_list_id' => $this->hufList->id, 'price_list_id' => $czk->id])
            ->assertHasActionErrors(['price_list_id']);
        $this->assertDatabaseCount('pricing_calculation_rows', 3);
    }

    public function test_livewire_foreign_calculation_manual_price_and_approval_actions_work(): void
    {
        $page = Livewire::test(PricingForeignRetailCalculations::class)
            ->callAction('calculateForeignRetail', ['source_price_list_id' => $this->hufList->id, 'price_list_id' => $this->list->id])
            ->assertHasNoErrors();
        $page->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '4.95')
            ->callTableAction('approveRetail', $this->retail(), ['approval_status' => 'approved'])->assertHasNoErrors();
        $this->assertSame('4.9500', $this->retail()->final_price);
        $this->assertSame('modified_approved', $this->retail()->status);
        $page->mountTableAction('retailDetails', $this->retail());
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Szezonárfolyam', $html);
        $this->assertStringContainsString('Országszorzó', $html);
    }

    public function test_failed_recalculation_keeps_existing_price_but_marks_it_for_review(): void
    {
        $this->calculate();
        $before = $this->retail()->final_price;
        ExchangeRate::where('currency_id', $this->list->currency_id)->update(['active' => false]);
        $this->assertSame(1, $this->calculate()['skipped']);
        $this->assertTrue($this->retail()->requires_review);
        $this->assertSame($before, $this->retail()->final_price);
    }

    public function test_bulk_foreign_approval_and_unchanged_recalculation_preserve_approval(): void
    {
        $this->calculate();
        Livewire::test(PricingForeignRetailCalculations::class)->selectTableRecords([$this->retail()])
            ->callTableBulkAction('approveRetailBulk', [$this->retail()], ['approval_status' => 'approved_round_2'])->assertHasNoErrors();
        $this->assertSame('approved_round_2', $this->retail()->status);
        $this->calculate();
        $this->assertFalse($this->retail()->requires_review);
        $this->assertNull($this->retail()->retained_price);
    }

    public function test_foreign_column_width_save_keeps_the_small_response_without_a_table_rerender(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingForeignRetailCalculations::class)->call('savePricingColumnWidths', ['final-price' => 110]);
        $this->assertArrayNotHasKey('html', $page->effects);
        $payload = json_encode(['snapshot' => $page->snapshot, 'effects' => $page->effects], JSON_THROW_ON_ERROR);
        $this->assertLessThan(20000, strlen($payload));
    }

    private function calculate(?PriceList $list = null): array
    {
        return app(ForeignRetailCalculationService::class)->recalculate($this->project, $this->hufList, $list ?? $this->list);
    }

    private function retail(?PriceList $list = null): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_list_id', ($list ?? $this->list)->id)->firstOrFail();
    }

    private function makeList(string $code, float $rate): PriceList
    {
        $currency = Currency::create(['code' => $code, 'name_hu' => $code, 'active' => true]);
        $list = PriceList::create(['code' => 'RETAIL-'.$code, 'name_hu' => $code.' kisker', 'type' => 'retail', 'currency_id' => $currency->id, 'active' => true]);
        ExchangeRate::create(['season_id' => $this->project->season_id, 'currency_id' => $currency->id, 'rate_to_huf' => $rate, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'country_multiplier', 'scope_type' => 'global',
            'price_list_id' => $list->id, 'currency_id' => $currency->id, 'value' => 1.1, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $code.' 95', 'price_type' => 'retail',
            'price_list_id' => $list->id, 'currency_id' => $currency->id, 'round_to' => 1, 'mode' => 'nearest', 'adjustment' => -0.05, 'active' => true]);

        return $list;
    }
}
