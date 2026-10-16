<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Models\Color;
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
use App\Services\Pricing\WholesaleCalculationService;
use App\Services\Pricing\WholesalePriceReviewService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WholesaleCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PriceList $retailHuf;

    private PriceList $retailEur;

    private PriceList $huf;

    private PriceList $eur;

    private PricingParameter $parameter;

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
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => '2027 árprojekt', 'status' => 'active', 'active' => false]);
        $huf = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name_hu' => 'Euró', 'active' => true]);
        $this->retailHuf = PriceList::create(['code' => 'RETAIL_HU', 'name_hu' => 'Magyar kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->retailEur = PriceList::create(['code' => 'RETAIL_EU', 'name_hu' => 'Euró kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $this->huf = PriceList::create(['code' => 'WHOLE_HU', 'name_hu' => 'Magyar nagyker', 'type' => 'wholesale', 'currency_id' => $huf->id, 'retail_price_list_id' => $this->retailHuf->id, 'active' => true]);
        $this->eur = PriceList::create(['code' => 'WHOLE_EU', 'name_hu' => 'Euró nagyker', 'type' => 'wholesale', 'currency_id' => $eur->id, 'retail_price_list_id' => $this->retailEur->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id, 'currency_id' => $huf->id, 'price_type' => 'cost', 'calculated_price' => 460]);
        ExchangeRate::create(['season_id' => $season->id, 'currency_id' => $eur->id, 'rate_to_huf' => 400, 'active' => true]);
        foreach (['retail_multiplier' => 4.7, 'country_multiplier' => 1.1, 'wholesale_margin' => 2.5] as $type => $value) {
            $parameter = PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => $type, 'scope_type' => 'global', 'value' => $value, 'active' => true]);
            if ($type === 'wholesale_margin') {
                $this->parameter = $parameter;
            }
        }
        foreach ([['retail', $this->retailHuf, 1000, -10], ['retail', $this->retailEur, 1, -0.05], ['wholesale', $this->huf, 100, 0], ['wholesale', $this->eur, 0.01, 0]] as [$type, $list, $step, $adjustment]) {
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $list->code, 'price_type' => $type,
                'price_list_id' => $list->id, 'currency_id' => $list->currency_id, 'round_to' => $step, 'mode' => 'nearest', 'adjustment' => $adjustment, 'active' => true]);
        }
        app(HufRetailCalculationService::class)->recalculate($this->project, $this->retailHuf);
        app(ForeignRetailCalculationService::class)->recalculate($this->project, $this->retailHuf, $this->retailEur);
    }

    private function calculate(): array
    {
        return app(WholesaleCalculationService::class)->recalculate($this->project);
    }

    private function row(?PriceList $list = null): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_type', 'wholesale')->where('price_list_id', ($list ?? $this->huf)->id)->firstOrFail();
    }

    public function test_gross_retail_is_divided_directly_into_net_wholesale_in_both_currencies(): void
    {
        $result = $this->calculate();
        $this->assertSame(2, $result['created']);
        $this->assertSame('1990.0000', $this->row()->base_price);
        $this->assertSame('796.0000', $this->row()->unrounded_price);
        $this->assertSame('800.0000', $this->row()->final_price);
        $this->assertSame('4.9500', $this->row($this->eur)->base_price);
        $this->assertSame('1.9800', $this->row($this->eur)->final_price);
        $this->assertEquals(2.4875, $this->row()->effective_wholesale_multiplier);
        $this->assertEquals(2.5, $this->row($this->eur)->effective_wholesale_multiplier);
        $this->assertSame(0, $this->calculate()['created']);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_page_calculation_and_vat_comparisons_do_not_alter_prices_or_approvals(): void
    {
        $page = Livewire::test(PricingWholesaleCalculations::class)->callAction('calculateAllWholesale')->assertHasNoErrors();
        $this->assertSame(27.0, $page->instance()->wholesaleVatPercent());
        $this->assertEqualsWithDelta(1990 / 1.27 / 800, $page->instance()->averageNetNetMultiplier, 0.0000001);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 1,96"', false);
        app(WholesalePriceReviewService::class)->approveCurrentPrice($this->row($this->eur));
        $before = $this->row($this->eur)->getAttributes();
        $page->call('selectWholesaleCurrency', 'EUR');
        $this->assertNull($page->instance()->averageNetNetMultiplier);
        $page->call('setEurVatPercent', '20,0')->assertHasNoErrors();
        $this->assertEqualsWithDelta(2.5 / 1.2, $page->instance()->averageNetNetMultiplier, 0.0000001);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 2,08"', false);
        $this->assertSame($before, $this->row($this->eur)->getAttributes());
        $this->assertSame(20.0, Livewire::test(PricingWholesaleCalculations::class)->instance()->eurVatPercent);
        $page->call('setEurVatPercent', 0);
        $this->assertEquals(2.5, $page->instance()->averageNetNetMultiplier);
        $page->call('setEurVatPercent', '')->assertHasNoErrors();
        $this->assertNull($page->instance()->averageNetNetMultiplier);
        $page->call('setEurVatPercent', -1)->assertHasErrors('eurVatPercent');
        $page->call('setEurVatPercent', 101)->assertHasErrors('eurVatPercent');
    }

    public function test_single_currency_action_auto_selects_unique_target_and_uses_the_linked_retail(): void
    {
        Livewire::test(PricingWholesaleCalculations::class)->callAction('calculateWholesale')->assertHasNoErrors();
        $this->assertSame('800.0000', $this->row()->final_price);
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $this->eur->id)->count());
    }

    public function test_wrong_currency_link_is_rejected_before_any_price_is_written(): void
    {
        $this->eur->update(['retail_price_list_id' => $this->retailHuf->id]);
        try {
            $this->calculate();
            $this->fail('Invalid pairings cannot produce a partial batch.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Kapcsolt kisker', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_type', 'wholesale')->count());
    }

    public function test_missing_or_ambiguous_multiplier_is_logged_without_guessing(): void
    {
        $this->parameter->update(['active' => false]);
        $this->assertSame(2, $this->calculate()['skipped']);
        $this->parameter->update(['active' => true]);
        $this->parameter->replicate()->save();
        $this->assertSame(2, $this->calculate()['skipped']);
        $this->assertSame(0, PricingCalculationRow::where('price_type', 'wholesale')->count());
        $this->assertDatabaseCount('pricing_calculation_errors', 4);
    }

    public function test_more_specific_model_multiplier_overrides_the_global_value(): void
    {
        $parameter = $this->parameter->replicate();
        $parameter->scope_type = 'product';
        $parameter->product_id = Product::firstOrFail()->id;
        $parameter->value = 3;
        $parameter->save();
        $this->calculate();
        $this->assertSame('3.000000', $this->row()->multiplier);
        $this->assertSame('700.0000', $this->row()->final_price);
        $this->assertSame('1.6500', $this->row($this->eur)->final_price);
    }

    public function test_manual_price_bulk_approval_and_details_use_the_wholesale_workflow(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingWholesaleCalculations::class);
        $page->call('updateTableColumnState', 'manual_price', (string) $this->row()->id, '1000')->assertHasNoErrors();
        $this->assertEquals(1.99, $this->row()->effective_wholesale_multiplier);
        $page->callTableBulkAction('approveRetailBulk', [$this->row()], ['approval_status' => 'approved'])->assertHasNoErrors();
        $this->assertSame('modified_approved', $this->row()->status);
        $page->call('openPricingDetails', $this->row()->id);
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Nagyker szorzó', $html);
        $this->assertStringContainsString('Nettó/nettó szorzó', $html);
        $this->assertStringContainsString('1000', str_replace(' ', '', strip_tags($html)));
        $this->assertCount(3, $page->instance()->pricingRowOperations($this->row()));
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $page->call('unmountAction')->callTableBulkAction('pricingAcceptBulk', [$this->row()])->assertHasNoErrors();
        $this->assertNull($this->row()->manual_price);
        $this->assertSame($this->row()->calculated_price, $this->row()->final_price);
        Livewire::test(PricingWholesaleCalculations::class)
            ->mountAction('pairPreviousSeasonProduct', ['record_id' => $this->row()->id])->assertActionNotMounted();
    }

    public function test_approved_price_is_retained_when_retail_changes_and_can_be_reapproved_after_recalculation(): void
    {
        $this->calculate();
        $review = app(WholesalePriceReviewService::class);
        $review->approveCurrentPrice($this->row($this->eur));
        $source = PricingCalculationRow::where('price_list_id', $this->retailEur->id)->firstOrFail();
        app(RetailPriceReviewService::class)->setManualPrice($source, 3.95);
        $this->assertTrue($this->row($this->eur)->requires_review);
        $this->assertFalse($this->row()->requires_review);
        try {
            $review->approveCurrentPrice($this->row($this->eur));
            $this->fail('Stale calculations cannot be approved.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('újraszámítást', $exception->getMessage());
        }
        $this->calculate();
        $this->assertSame('1.9800', $this->row($this->eur)->final_price);
        $this->assertSame('1.5800', $this->row($this->eur)->calculated_price);
        $this->assertTrue($this->row($this->eur)->requires_review);
        $review->approveCurrentPrice($this->row($this->eur));
        $this->assertFalse($this->row($this->eur)->requires_review);
        $review->acceptCalculatedPrice($this->row($this->eur));
        $this->assertSame('1.5800', $this->row($this->eur)->final_price);
    }

    public function test_parameter_change_requires_review_even_if_rounded_price_is_unchanged(): void
    {
        $this->calculate();
        $review = app(WholesalePriceReviewService::class);
        $review->approveCurrentPrice($this->row());
        $this->parameter->update(['value' => 2.51]);
        $this->calculate();
        $this->assertSame('800.0000', $this->row()->calculated_price);
        $this->assertTrue($this->row()->requires_review);
        $review->approveCurrentPrice($this->row());
        $this->assertFalse($this->row()->requires_review);
    }

    public function test_cost_change_marks_both_direct_and_indirect_wholesale_dependents(): void
    {
        $this->calculate();
        PricingCalculationRow::where('price_type', 'cost')->firstOrFail()->update(['calculated_price' => 500]);
        $this->assertTrue($this->row()->requires_review);
        $this->assertTrue($this->row($this->eur)->requires_review);
        $this->assertSame(2, $this->calculate()['skipped']);
    }

    public function test_archive_and_permissions_protect_prices_but_viewers_can_set_personal_vat(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        foreach (['View:PricingWholesaleCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($reader);
        $this->assertFalse(PricingHufRetailCalculations::canAccess());
        Livewire::test(PricingWholesaleCalculations::class)->assertSuccessful()->assertActionHidden('calculateWholesale')
            ->assertTableBulkActionHidden('approveRetailBulk')->call('selectWholesaleCurrency', 'EUR')->call('setEurVatPercent', 19)->assertHasNoErrors();
        $this->assertSame(19.0, Livewire::test(PricingWholesaleCalculations::class)->instance()->eurVatPercent);
        $this->project->update(['status' => 'closed']);
        Livewire::test(PricingWholesaleCalculations::class)->call('setPricingArchive', true)->assertActionHidden('calculateAllWholesale');
        $this->expectException(AuthorizationException::class);
        $this->calculate();
    }

    public function test_net_net_values_are_numeric_in_excel_and_can_be_sorted_searched_and_formatted(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingWholesaleCalculations::class)->call('selectWholesaleCurrency', 'EUR')->call('setEurVatPercent', 25);
        $page->sortTable('net_net_multiplier')->searchTableColumns(['net_net_multiplier' => '2'])
            ->assertHasNoErrors()->assertCanSeeTableRecords([$this->row($this->eur)]);
        $column = $page->instance()->getTable()->getColumn('net_net_multiplier');
        $export = new AdminTableExport($page->instance()->getTableQueryForExport(), [$column]);
        $this->assertSame([2.0], $export->map($this->row($this->eur)));
        $page->callAction('decimalPlaces', ['net_net_multiplier' => 3])->assertHasNoErrors();
        $this->assertSame(['A' => '#,##0.000'], $page->instance()->pricingDecimalExportFormats([$column]));
        $page->assertSee('data-pricing-average-multiplier="Átlag: 2,00"', false);
    }

    public function test_eur_vat_settings_are_private_to_each_user_and_do_not_override_huf_27_percent(): void
    {
        $first = auth()->user();
        Livewire::test(PricingWholesaleCalculations::class)->call('selectWholesaleCurrency', 'EUR')->call('setEurVatPercent', 19);
        $second = User::factory()->create();
        $second->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($second);
        $page = Livewire::test(PricingWholesaleCalculations::class);
        $this->assertNull($page->instance()->eurVatPercent);
        $page->call('selectWholesaleCurrency', 'EUR')->call('setEurVatPercent', 21);
        $this->actingAs($first);
        $page = Livewire::test(PricingWholesaleCalculations::class);
        $this->assertSame(19.0, $page->instance()->eurVatPercent);
        $page->call('selectWholesaleCurrency', 'HUF');
        $this->assertSame(27.0, $page->instance()->wholesaleVatPercent());
        $page->call('selectWholesaleCurrency', 'EUR');
        $this->assertSame(19.0, $page->instance()->wholesaleVatPercent());
    }

    public function test_wholesale_page_has_an_independent_shield_permission(): void
    {
        $pages = FilamentShield::getPages();
        $this->assertArrayHasKey('View:PricingWholesaleCalculations', $pages[PricingWholesaleCalculations::class]['permissions']);
        $reader = User::factory()->create();
        foreach (['View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($reader);
        $this->assertFalse(PricingWholesaleCalculations::canAccess());
        $this->get('/admin/pricing-wholesale-calculations')->assertForbidden();
    }

    public function test_server_rechecks_closed_project_status_before_recalculation(): void
    {
        PricingProject::whereKey($this->project->id)->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Lezárt árprojekt');
        $this->calculate();
    }

    public function test_changed_exchange_rate_invalidates_the_eur_retail_source_for_approval(): void
    {
        $this->calculate();
        ExchangeRate::where('currency_id', $this->eur->currency_id)->update(['rate_to_huf' => 500]);
        $this->assertSame(1, $this->calculate()['skipped']);
        $this->assertSame('1.9800', $this->row($this->eur)->final_price);
        $this->assertTrue($this->row($this->eur)->requires_review);
        $this->expectException(DomainException::class);
        app(WholesalePriceReviewService::class)->approveCurrentPrice($this->row($this->eur));
    }

    public function test_global_rounding_is_not_silently_used_for_eur_wholesale(): void
    {
        $this->calculate();
        PricingRoundingRule::where('price_type', 'wholesale')->delete();
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'HUF globális', 'price_type' => 'wholesale',
            'round_to' => 100, 'mode' => 'nearest', 'adjustment' => 0, 'active' => true]);
        $this->assertSame(1, $this->calculate()['skipped']);
        $this->assertSame('1.9800', $this->row($this->eur)->final_price);
        $this->assertTrue($this->row($this->eur)->requires_review);
    }

    public function test_forged_currency_record_cannot_be_edited_from_the_other_currency_tab(): void
    {
        $this->calculate();
        Livewire::test(PricingWholesaleCalculations::class)
            ->call('updateTableColumnState', 'manual_price', (string) $this->row($this->eur)->id, '10');
        $this->assertNull($this->row($this->eur)->manual_price);
        $this->assertSame('1.9800', $this->row($this->eur)->final_price);
    }

    public function test_value_weighted_average_includes_all_pages_and_follows_filters(): void
    {
        $this->calculate();
        $first = $this->row();
        $first->update(['base_price' => 200, 'calculated_price' => 100]);
        for ($index = 1; $index <= 50; $index++) {
            $color = Color::create(['product_id' => $first->product_id, 'code' => 'C'.$index, 'name_hu' => 'Szín '.$index, 'active' => true]);
            $row = $first->replicate();
            $row->color_id = $color->id;
            $row->base_price = 1200;
            $row->calculated_price = 300;
            $row->save();
        }
        $page = Livewire::test(PricingWholesaleCalculations::class)->set('tableRecordsPerPage', 50);
        $this->assertSame(50, $page->instance()->getTableRecords()->count());
        $this->assertEqualsWithDelta((200 + 50 * 1200) / (100 + 50 * 300), $page->instance()->averageRetailMultiplier, 0.0000001);
        $this->assertEqualsWithDelta((200 + 50 * 1200) / (100 + 50 * 300) / 1.27, $page->instance()->averageNetNetMultiplier, 0.0000001);
        $page->searchTableColumns(['color.name_hu' => 'Szín 50']);
        $this->assertEquals(4, $page->instance()->averageRetailMultiplier);
        $page->searchTableColumns(['color.name_hu' => 'NINCS']);
        $this->assertNull($page->instance()->averageRetailMultiplier);
        $this->assertNull($page->instance()->averageNetNetMultiplier);
    }

    public function test_retail_review_service_cannot_modify_wholesale_rows(): void
    {
        $this->calculate();
        $this->expectException(DomainException::class);
        app(RetailPriceReviewService::class)->setManualPrice($this->row(), 1000);
    }
}
