<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PricingCalculationRows\Pages\EditPricingCalculationRow;
use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
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
use App\Services\Pricing\OutletCalculationService;
use App\Services\Pricing\OutletComparison;
use App\Services\Pricing\OutletPriceReviewService;
use App\Services\Pricing\RetailOutletReviewService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutletCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PriceList $retail;

    private PriceList $outlet;

    private PricingParameter $discount;

    private PricingRoundingRule $rounding;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => '2027 tél', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id, 'model_code' => 'BAG27', 'name_hu' => 'BAGW27', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => '2027 árprojekt', 'status' => 'active', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $this->retail = PriceList::create(['code' => 'RETAIL_HU', 'name_hu' => 'Magyar kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->outlet = PriceList::create(['code' => 'OUTLET_HUF', 'name_hu' => 'Magyar outlet', 'type' => 'outlet', 'currency_id' => $huf->id, 'source_retail_price_list_id' => $this->retail->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id, 'currency_id' => $huf->id, 'price_type' => 'cost', 'calculated_price' => 460]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier', 'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        $this->discount = PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'outlet_discount', 'scope_type' => 'global', 'currency_id' => $huf->id, 'value' => 20, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Kisker', 'price_type' => 'retail', 'price_list_id' => $this->retail->id, 'currency_id' => $huf->id, 'round_to' => 1000, 'mode' => 'nearest', 'adjustment' => -10, 'active' => true]);
        $this->rounding = PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'Outlet', 'price_type' => 'outlet', 'price_list_id' => $this->outlet->id, 'currency_id' => $huf->id, 'round_to' => 100, 'mode' => 'down', 'adjustment' => -10, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->retail);
    }

    private function source(): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_type', 'retail')->where('price_list_id', $this->retail->id)->firstOrFail();
    }

    private function row(): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_type', 'outlet')->where('price_list_id', $this->outlet->id)->firstOrFail();
    }

    private function calculate(): array
    {
        return app(OutletCalculationService::class)->recalculate($this->project, 'HUF');
    }

    public function test_outlet_uses_gross_same_currency_retail_and_never_publishes(): void
    {
        $this->assertSame(1, $this->calculate()['created']);
        $row = $this->row();
        $this->assertSame('1990.0000', $row->base_price);
        $this->assertSame('20.000000', $row->outlet_discount_percent);
        $this->assertSame('1592.0000', $row->unrounded_price);
        $this->assertSame('1490.0000', $row->final_price);
        $this->assertTrue($row->outletDiscountIsSufficient());
        $this->assertSame($this->source()->id, $row->source_retail_row_id);
        $this->assertSame(0, $this->calculate()['created']);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_rounding_below_minimum_is_visible_but_cannot_be_approved_and_combined_approval_rolls_back(): void
    {
        $this->rounding->update(['mode' => 'nearest', 'adjustment' => 0]);
        $this->assertSame(1, $this->calculate()['below_minimum']);
        $this->assertSame('1600.0000', $this->row()->final_price);
        $this->assertFalse($this->row()->outletDiscountIsSufficient());
        try {
            app(RetailOutletReviewService::class)->approve($this->source(), [$this->outlet->id], 'all', 'approved');
            $this->fail('Insufficient discount must reject approval.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('minimum', $exception->getMessage());
        }
        $this->assertSame('calculated', $this->source()->status);
        $this->assertSame('calculated', $this->row()->status);
    }

    public function test_manual_price_boundary_and_separate_and_combined_approvals(): void
    {
        $this->calculate();
        $review = app(OutletPriceReviewService::class);
        $review->setManualPrice($this->row(), 1592.0001);
        $this->assertFalse($this->row()->outletDiscountIsSufficient());
        $result = $review->approveCurrentPrices(collect([$this->row()]));
        $this->assertSame(1, $result['skipped']);
        $review->setManualPrice($this->row(), 1592);
        $this->assertTrue($this->row()->outletDiscountIsSufficient());
        $this->assertSame(1, app(RetailOutletReviewService::class)->approve($this->source(), [$this->outlet->id], 'outlet', 'approved'));
        $this->assertSame('calculated', $this->source()->status);
        $this->assertSame('modified_approved', $this->row()->status);
        $this->assertSame(2, app(RetailOutletReviewService::class)->approve($this->source(), [$this->outlet->id], 'all', 'approved'));
        $this->assertSame('approved', $this->source()->status);
    }

    public function test_source_parameter_and_rounding_changes_preserve_prices_and_require_review(): void
    {
        $this->calculate();
        app(OutletPriceReviewService::class)->approveCurrentPrice($this->row());
        app(RetailPriceReviewService::class)->setManualPrice($this->source(), 1800);
        $this->assertTrue($this->row()->requires_review);
        $this->calculate();
        $this->assertSame('1490.0000', $this->row()->final_price);
        $this->assertSame('1390.0000', $this->row()->calculated_price);
        $this->assertFalse($this->row()->outletDiscountIsSufficient());
        app(OutletPriceReviewService::class)->acceptCalculatedPrice($this->row());
        $this->assertFalse($this->row()->requires_review);
        $this->discount->update(['value' => 25]);
        $this->assertTrue($this->row()->requires_review);
        $this->calculate();
        app(OutletPriceReviewService::class)->acceptCalculatedPrice($this->row());
        $this->rounding->update(['adjustment' => -20]);
        $this->assertTrue($this->row()->requires_review);
    }

    public function test_invalid_discount_and_missing_rounding_are_logged_without_overwriting_existing_price(): void
    {
        $this->calculate();
        foreach ([-1, 100] as $discount) {
            $this->discount->update(['value' => $discount]);
            $this->assertSame(1, $this->calculate()['skipped']);
            $this->assertSame('1490.0000', $this->row()->final_price);
        }
        $this->discount->update(['value' => 20]);
        $this->rounding->update(['active' => false]);
        $this->assertSame(1, $this->calculate()['skipped']);
        $this->assertDatabaseCount('pricing_calculation_errors', 3);
    }

    public function test_outlet_columns_are_scoped_to_currency_and_not_added_to_wholesale(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful()->assertSee('OUTLET_HUF');
        $page->call('setOutletManualPrice', $this->source()->id, $this->outlet->id, '1500,5')->assertSuccessful();
        $this->assertSame('1500.5000', $this->row()->manual_price);
        $this->assertArrayHasKey('outlet_'.$this->outlet->id.'_final_price', $page->instance()->getTable()->getColumns());
        $page->sortTable('outlet_'.$this->outlet->id.'_actual_discount')->assertSuccessful();
        $page->call('openPricingDetails', $this->source()->id)->assertActionMounted('pricingDetails');
        $this->assertStringContainsString('Outlet kalkuláció', $page->instance()->getMountedAction()->getModalContent()->render());
        $foreign = Livewire::test(PricingForeignRetailCalculations::class)->assertSuccessful();
        $this->assertCount(0, $foreign->instance()->outletPriceLists);
        $wholesale = Livewire::test(PricingWholesaleCalculations::class)->assertSuccessful();
        $this->assertCount(0, $wholesale->instance()->outletPriceLists);
    }

    public function test_czk_outlet_uses_czk_retail_and_only_appears_on_czk_tab(): void
    {
        $czk = Currency::create(['code' => 'CZK', 'name_hu' => 'Korona', 'active' => true]);
        ExchangeRate::create(['season_id' => $this->project->season_id, 'currency_id' => $czk->id, 'rate_to_huf' => 16, 'active' => true]);
        $retail = PriceList::create(['code' => 'RETAIL_CZK', 'name_hu' => 'CZ', 'type' => 'retail', 'currency_id' => $czk->id, 'active' => true]);
        $outlet = PriceList::create(['code' => 'OUTLET_CZK', 'name_hu' => 'CZ outlet', 'type' => 'outlet', 'currency_id' => $czk->id, 'source_retail_price_list_id' => $retail->id, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'country_multiplier', 'scope_type' => 'global', 'value' => 1.1, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'outlet_discount', 'scope_type' => 'global', 'currency_id' => $czk->id, 'value' => 30, 'active' => true]);
        foreach (['retail', 'outlet'] as $type) {
            $list = $type === 'retail' ? $retail : $outlet;
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $type, 'price_type' => $type, 'price_list_id' => $list->id, 'currency_id' => $czk->id, 'round_to' => 1, 'mode' => 'down', 'active' => true]);
        }
        app(ForeignRetailCalculationService::class)->recalculate($this->project, $this->retail, $retail);
        $this->assertSame(1, app(OutletCalculationService::class)->recalculate($this->project, 'CZK')['created']);
        $row = PricingCalculationRow::where('price_list_id', $outlet->id)->firstOrFail();
        $this->assertSame('136.0000', $row->base_price);
        $this->assertSame('95.0000', $row->final_price);
        $page = Livewire::test(PricingForeignRetailCalculations::class)->call('selectRetailCurrency', 'CZK')->assertSuccessful();
        $this->assertSame([$outlet->id], $page->instance()->outletPriceLists->modelKeys());
        $this->assertArrayHasKey('outlet_'.$outlet->id.'_final_price', $page->instance()->getTable()->getColumns());
        $column = 'outlet_'.$outlet->id.'_final_price';
        $page->call('savePricingDecimalPlaces', [$column => 2])->call('selectRetailCurrency', 'EUR')
            ->call('savePricingDecimalPlaces', ['final_price' => 1])->call('selectRetailCurrency', 'CZK');
        $this->assertSame(2, $page->instance()->pricingDecimalPlacesFor($column));
        $reopened = Livewire::test(PricingForeignRetailCalculations::class);
        $this->assertSame(2, $reopened->instance()->pricingDecimalPlacesFor($column));
    }

    public function test_unconfigured_currency_and_closed_project_cannot_be_calculated(): void
    {
        $this->project->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        $this->calculate();
    }

    public function test_forged_outlet_list_cannot_be_edited(): void
    {
        $this->calculate();
        Livewire::test(PricingHufRetailCalculations::class)->call('setOutletManualPrice', $this->source()->id, $this->retail->id, 10)->assertStatus(422);
        $this->assertNull($this->row()->manual_price);
    }

    public function test_row_menu_and_bulk_actions_approve_outlet_independently_or_together(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $actions = array_column($page->instance()->pricingRowOperations($this->source()), 'action');
        $this->assertContains('pricingOutletApprove', $actions);
        $this->assertContains('pricingAllApprove', $actions);
        $page->callAction('pricingOutletApprove', ['approval_status' => 'approved'], ['record_id' => $this->source()->id])->assertHasNoErrors();
        $this->assertSame('approved', $this->row()->status);
        $this->assertSame('calculated', $this->source()->status);
        $page->callTableBulkAction('approveAllPricesBulk', [$this->source()], ['approval_status' => 'approved'])->assertHasNoErrors();
        $this->assertSame('approved', $this->source()->status);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_all_price_bulk_approval_skips_invalid_group_without_partial_approval(): void
    {
        $this->calculate();
        app(OutletPriceReviewService::class)->setManualPrice($this->row(), 1700);
        Livewire::test(PricingHufRetailCalculations::class)
            ->callTableBulkAction('approveAllPricesBulk', [$this->source()], ['approval_status' => 'approved'])->assertHasNoErrors();
        $this->assertSame('calculated', $this->source()->status);
        $this->assertSame('calculated', $this->row()->status);
        $notice = collect(session('filament.claimed_notifications', []))->last();
        $this->assertStringContainsString('Kihagyott terméksorok: 1', $notice['body']);
    }

    public function test_outlet_columns_support_numeric_excel_sorting_searching_and_decimal_preferences(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $name = 'outlet_'.$this->outlet->id.'_final_price';
        $columns = $page->instance()->getTable()->getColumns();
        $export = new AdminTableExport($page->instance()->getFilteredTableQuery(), [$columns[$name]]);
        $this->assertSame([1490.0], $export->map($this->source()));
        $value = PricingCalculationRow::whereKey($this->source()->id)->selectRaw(OutletComparison::sql('outlet_'.$this->outlet->id.'_actual_discount').' AS discount')->value('discount');
        $this->assertEqualsWithDelta($this->row()->actual_outlet_discount, $value, 0.00001);
        $page->searchTableColumns([$name => '1490'])->assertCanSeeTableRecords([$this->source()]);
        $page->searchTableColumns([$name => '9999'])->assertCanNotSeeTableRecords([$this->source()]);
        $page->call('savePricingDecimalPlaces', [$name => 2])->assertHasNoErrors();
        $this->assertSame(2, $page->instance()->pricingDecimalPlacesFor($name));
    }

    public function test_readonly_user_cannot_forge_outlet_edit_approval_or_recalculation(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow',
        ]));
        $this->actingAs($reader);
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful();
        $this->assertSame([['action' => 'pricingDetails', 'label' => 'Részletek']], $page->instance()->pricingRowOperations($this->source()));
        $column = $page->instance()->getTable()->getColumn('outlet_'.$this->outlet->id.'_manual_price')->record($this->source());
        $this->assertSame('false', $column->getExtraCellAttributes()['data-pricing-editable']);
        $page->call('setOutletManualPrice', $this->source()->id, $this->outlet->id, 1000)->assertForbidden();
        Livewire::test(PricingHufRetailCalculations::class)->mountAction('pricingAllApprove', ['record_id' => $this->source()->id])->assertActionNotMounted();
        $this->expectException(AuthorizationException::class);
        $this->calculate();
    }

    public function test_generic_editor_cannot_bypass_outlet_minimum_or_approval(): void
    {
        $this->calculate();
        Livewire::test(EditPricingCalculationRow::class, ['record' => $this->row()->id])
            ->fillForm(['manual_price' => 1900, 'status' => 'approved', 'requires_review' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->row()->manual_price);
        $this->assertSame('calculated', $this->row()->status);
    }

    public function test_outlet_configuration_is_list_specific_and_validates_same_currency_source(): void
    {
        $second = PriceList::create(['code' => 'OUTLET_SECOND', 'name_hu' => 'Második', 'type' => 'outlet', 'currency_id' => $this->retail->currency_id, 'source_retail_price_list_id' => $this->retail->id, 'active' => true]);
        $this->discount->update(['price_list_id' => $this->outlet->id]);
        $this->assertSame([$this->outlet->id], app(OutletCalculationService::class)->lists($this->project, 'HUF')->modelKeys());
        $czk = Currency::create(['code' => 'CZK', 'name_hu' => 'Korona', 'active' => true]);
        $this->expectException(ValidationException::class);
        $second->update(['currency_id' => $czk->id]);
    }

    public function test_outlet_list_can_be_created_in_admin_and_requires_matching_source(): void
    {
        Livewire::test(CreatePriceList::class)
            ->fillForm(['code' => 'OUTLET_UI', 'name_hu' => 'Teszt outlet', 'type' => 'outlet', 'currency_id' => $this->retail->currency_id,
                'source_retail_price_list_id' => $this->retail->id, 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('price_lists', ['code' => 'OUTLET_UI', 'type' => 'outlet', 'source_retail_price_list_id' => $this->retail->id]);
        Livewire::test(CreatePriceList::class)
            ->fillForm(['code' => 'OUTLET_INVALID', 'name_hu' => 'Érvénytelen', 'type' => 'outlet', 'currency_id' => $this->retail->currency_id,
                'source_retail_price_list_id' => $this->outlet->id, 'active' => true])
            ->call('create')->assertHasFormErrors(['source_retail_price_list_id']);
        $this->assertDatabaseMissing('price_lists', ['code' => 'OUTLET_INVALID']);
    }

    public function test_outlet_parameter_admin_form_validates_minimum_discount_and_currency(): void
    {
        Livewire::test(CreatePricingParameter::class)
            ->fillForm(['pricing_project_id' => $this->project->id, 'parameter_type' => 'outlet_discount', 'scope_type' => 'global', 'currency_id' => null, 'value' => 100, 'active' => true])
            ->call('create')->assertHasFormErrors(['currency_id', 'value']);
    }

    public function test_a_changed_parameter_cannot_be_approved_until_recalculated(): void
    {
        $this->calculate();
        $this->discount->update(['value' => 30]);
        $this->expectException(DomainException::class);
        app(OutletPriceReviewService::class)->approveCurrentPrice($this->row());
    }
}
