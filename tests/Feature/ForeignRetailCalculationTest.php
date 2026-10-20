<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
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
use App\Services\Pricing\ForeignRetailCalculator;
use App\Services\Pricing\ForeignRetailPriceListResolver;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\RetailCalculationDependencyService;
use App\Services\Pricing\RetailPriceReviewService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->project->products()->attach($product);
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
            ->callAction('pricingApprove', ['approval_status' => 'approved'], ['record_id' => $this->retail()->id])->assertHasNoErrors();
        $this->assertSame('4.9500', $this->retail()->final_price);
        $this->assertSame('modified_approved', $this->retail()->status);
        $page->call('openPricingDetails', $this->retail()->id);
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Szezonárfolyam', $html);
        $this->assertStringContainsString('Országszorzó', $html);
        $this->assertCount(4, $page->instance()->pricingRowOperations($this->retail()));
        $this->assertSame('Termékenkénti árkezelés', $page->instance()->pricingRowOperations($this->retail())[3]['label']);
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $page->call('unmountAction')->callTableBulkAction('pricingAcceptBulk', [$this->retail()])->assertHasNoErrors();
        $this->assertNull($this->retail()->manual_price);
        $this->assertSame($this->retail()->calculated_price, $this->retail()->final_price);
        Livewire::test(PricingForeignRetailCalculations::class)
            ->mountAction('pairPreviousSeasonProduct', ['record_id' => $this->retail()->id])->assertActionNotMounted();
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

    public function test_unique_single_currency_pair_is_selected_without_visible_dropdowns(): void
    {
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('calculateForeignRetail')
            ->assertFormFieldHidden('source_price_list_id')->assertFormFieldHidden('price_list_id')
            ->callMountedAction()->assertHasNoErrors();
        $this->assertSame($this->source->id, $this->retail()->source_retail_row_id);
    }

    public function test_single_currency_only_asks_for_the_ambiguous_source(): void
    {
        $other = $this->makeHufSource('OTHER-HUF');
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('calculateForeignRetail')
            ->assertFormFieldVisible('source_price_list_id')->assertFormFieldHidden('price_list_id');
        Livewire::test(PricingForeignRetailCalculations::class)->callAction('calculateForeignRetail', ['source_price_list_id' => $other->id])
            ->assertHasNoErrors();
        $this->assertSame($other->id, $this->retail()->sourceRetailRow->price_list_id);
    }

    public function test_single_currency_only_asks_for_the_ambiguous_target(): void
    {
        $other = $this->makeSameCurrencyTarget('RETAIL-SK');
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('calculateForeignRetail')
            ->assertFormFieldHidden('source_price_list_id')->assertFormFieldVisible('price_list_id');
        Livewire::test(PricingForeignRetailCalculations::class)->callAction('calculateForeignRetail', ['price_list_id' => $other->id])
            ->assertHasNoErrors();
        $this->assertSame(1, PricingCalculationRow::where('price_list_id', $other->id)->count());
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $this->list->id)->count());
    }

    public function test_all_action_calculates_every_currency_regardless_of_current_tab_and_table_search(): void
    {
        $czk = $this->makeList('CZK', 16);
        $ron = $this->makeList('RON', 80);
        $pln = $this->makeList('PLN', 100);
        Livewire::test(PricingForeignRetailCalculations::class)->call('selectRetailCurrency', 'CZK')
            ->searchTable('no-matching-model')->callAction('calculateAllForeignRetail')->assertHasNoErrors();
        foreach ([$this->list, $czk, $ron, $pln] as $list) {
            $this->assertSame(1, PricingCalculationRow::where('price_list_id', $list->id)->count());
        }
        $this->assertCount(1, PricingCalculationRow::whereIn('price_list_id', [$this->list->id, $czk->id, $ron->id, $pln->id])
            ->get()->pluck('calculation_snapshot.run_id')->unique());
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_all_action_processes_multiple_country_price_lists_in_the_same_currency(): void
    {
        $sk = $this->makeSameCurrencyTarget('RETAIL-SK');
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('calculateAllForeignRetail')
            ->assertFormFieldHidden('sources.'.$this->list->id)->assertFormFieldHidden('sources.'.$sk->id)
            ->callMountedAction()->assertHasNoErrors();
        $this->assertSame('4.9500', $this->retail()->final_price);
        $this->assertSame('4.9500', $this->retail($sk)->final_price);
    }

    public function test_all_pairs_are_validated_before_any_price_is_created_when_sources_are_ambiguous(): void
    {
        $this->makeHufSource('OTHER-HUF');
        $this->makeList('CZK', 16);
        try {
            app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
            $this->fail('Ambiguous source lists must not be guessed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Több lehetséges', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::whereHas('currency', fn ($query) => $query->where('code', '!=', 'HUF'))->count());
        $this->assertDatabaseCount('pricing_calculation_errors', 0);
    }

    public function test_all_action_allows_a_different_explicit_huf_source_for_each_target(): void
    {
        $other = $this->makeHufSource('OTHER-HUF');
        $czk = $this->makeList('CZK', 16);
        Livewire::test(PricingForeignRetailCalculations::class)->mountAction('calculateAllForeignRetail')
            ->assertFormFieldVisible('sources.'.$this->list->id)->assertFormFieldVisible('sources.'.$czk->id);
        Livewire::test(PricingForeignRetailCalculations::class)->callAction('calculateAllForeignRetail', ['sources' => [
            $this->list->id => $this->hufList->id, $czk->id => $other->id,
        ]])->assertHasNoErrors();
        $this->assertSame($this->hufList->id, $this->retail()->sourceRetailRow->price_list_id);
        $this->assertSame($other->id, $this->retail($czk)->sourceRetailRow->price_list_id);
    }

    public function test_partial_source_choices_cannot_cause_a_partial_batch(): void
    {
        $this->makeHufSource('OTHER-HUF');
        $this->makeList('CZK', 16);
        try {
            app(ForeignRetailCalculationService::class)->recalculateAll($this->project, [$this->list->id => $this->hufList->id]);
            $this->fail('Every ambiguous pair must be selected before processing.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Több lehetséges', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $this->list->id)->count());
    }

    public function test_inactive_and_non_retail_price_lists_do_not_make_pairs_ambiguous(): void
    {
        $inactive = $this->makeSameCurrencyTarget('INACTIVE');
        $inactive->update(['active' => false]);
        $nonRetail = $this->makeSameCurrencyTarget('WHOLESALE');
        $nonRetail->update(['type' => 'wholesale']);
        $pair = app(ForeignRetailPriceListResolver::class)->resolvePair('EUR');
        $this->assertSame($this->hufList->id, $pair['source']->id);
        $this->assertSame($this->list->id, $pair['target']->id);
        $result = app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
        $this->assertSame(1, $result['price_lists']);
    }

    public function test_all_recalculation_rejects_stale_target_selections_without_writes(): void
    {
        $target = $this->makeSameCurrencyTarget('RETAIL-SK');
        $target->update(['active' => false]);
        try {
            app(ForeignRetailCalculationService::class)->recalculateAll($this->project, [$target->id => $this->hufList->id]);
            $this->fail('An inactive target cannot remain in the submitted plan.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('időközben megváltoztak', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $this->list->id)->count());
    }

    public function test_all_recalculation_rejects_invalid_huf_sources_before_any_writes(): void
    {
        $czk = $this->makeList('CZK', 16);
        try {
            app(ForeignRetailCalculationService::class)->recalculateAll($this->project, [
                $this->list->id => $this->hufList->id, $czk->id => $this->list->id,
            ]);
            $this->fail('A foreign target cannot be used as a HUF source.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('már nem választható', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_list_id', $this->list->id)->count());
    }

    public function test_all_recalculation_rechecks_project_status_from_the_database(): void
    {
        PricingProject::whereKey($this->project->id)->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Lezárt árprojekt');
        app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
    }

    public function test_all_recalculation_reports_missing_source_or_target_lists(): void
    {
        $this->hufList->update(['active' => false]);
        try {
            app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
            $this->fail('A missing HUF source must be reported.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Nincs aktív', $exception->getMessage());
        }
        $this->hufList->update(['active' => true]);
        $this->list->update(['active' => false]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nincs aktív EUR, CZK, RON vagy PLN');
        app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
    }

    public function test_all_action_is_hidden_for_archive_and_read_only_roles_and_service_rechecks_permissions(): void
    {
        $this->project->update(['status' => 'closed']);
        Livewire::test(PricingForeignRetailCalculations::class)->call('setPricingArchive', true)
            ->assertActionHidden('calculateAllForeignRetail');
        $this->project->update(['status' => 'active']);
        $reader = User::factory()->create();
        foreach (['ViewAny:PricingCalculationRow', 'View:PricingForeignRetailCalculations'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($reader);
        Livewire::test(PricingForeignRetailCalculations::class)->assertActionHidden('calculateAllForeignRetail');
        $this->expectException(AuthorizationException::class);
        app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
    }

    public function test_all_recalculation_preserves_manual_approved_prices_and_reports_totals(): void
    {
        $this->calculate();
        $review = app(RetailPriceReviewService::class);
        $review->setManualPrice($this->retail(), 3.95);
        $review->approveCurrentPrice($this->retail());
        $czk = $this->makeList('CZK', 16);
        ExchangeRate::where('currency_id', $this->list->currency_id)->update(['rate_to_huf' => 300]);
        $result = app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
        $this->assertSame(2, $result['price_lists']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['requires_review']);
        $this->assertSame('3.9500', $this->retail()->final_price);
        $this->assertTrue($this->retail()->requires_review);
        $this->assertNotNull($this->retail($czk));
    }

    public function test_failed_row_inputs_are_reported_under_the_same_batch_run_id(): void
    {
        $czk = $this->makeList('CZK', 16);
        ExchangeRate::where('currency_id', $czk->currency_id)->delete();
        $result = app(ForeignRetailCalculationService::class)->recalculateAll($this->project);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('pricing_calculation_errors', ['run_id' => $result['run_id']]);
    }

    public function test_unexpected_batch_failure_rolls_back_prices_from_previously_processed_lists(): void
    {
        $this->makeList('CZK', 16);
        $service = new class(app(ForeignRetailCalculator::class), app(RetailCalculationDependencyService::class)) extends ForeignRetailCalculationService
        {
            private int $calls = 0;

            public function recalculate(PricingProject $project, PriceList $sourceList, PriceList $targetList, ?string $runId = null, ?int $productId = null): array
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Unexpected failure');
                }

                return parent::recalculate($project, $sourceList, $targetList, $runId, $productId);
            }
        };
        try {
            $service->recalculateAll($this->project);
            $this->fail('Unexpected batch errors must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected failure', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::whereHas('currency', fn ($query) => $query->where('code', '!=', 'HUF'))->count());
    }

    public function test_cost_based_multiplier_uses_final_foreign_price_saved_rate_and_huf_cost(): void
    {
        foreach (['EUR' => 400, 'CZK' => 16, 'RON' => 80, 'PLN' => 100] as $currency => $rate) {
            $list = $currency === 'EUR' ? $this->list : $this->makeList($currency, $rate);
            $this->calculate($list);
            $row = $this->retail($list);
            $expected = (float) $row->final_price * $rate / 460;
            $this->assertEqualsWithDelta($expected, $row->cost_based_multiplier, 0.0000001);
            $this->assertEqualsWithDelta($expected, (float) PricingCalculationRow::whereKey($row->id)
                ->selectRaw(PricingCalculationRow::costBasedMultiplierSql().' AS sql_multiplier')->firstOrFail()->sql_multiplier, 0.0000001);
        }
        ExchangeRate::where('currency_id', $this->list->currency_id)->update(['rate_to_huf' => 500]);
        $this->assertEqualsWithDelta(4.95 * 400 / 460, $this->retail()->cost_based_multiplier, 0.0000001);
        $row = $this->retail();
        $row->update(['retained_price' => 3.95]);
        $this->assertEqualsWithDelta(3.95 * 400 / 460, $row->cost_based_multiplier, 0.0000001);
        $row->update(['manual_price' => 2.95]);
        $this->assertEqualsWithDelta(2.95 * 400 / 460, $row->cost_based_multiplier, 0.0000001);
    }

    public function test_cost_multiplier_sort_search_export_and_two_decimal_average_ignore_display_preferences(): void
    {
        $this->calculate();
        $first = $this->retail();
        $first->update(['calculated_price' => 2]);
        $product = $this->source->product->replicate();
        $product->model_code = 'SECOND-MODEL';
        $product->save();
        $source = $this->source->replicate();
        $source->product_id = $product->id;
        $source->base_price = 1000;
        $source->save();
        $second = $first->replicate();
        $second->product_id = $product->id;
        $second->source_retail_row_id = $source->id;
        $second->calculated_price = 10;
        $second->base_price = 20;
        $second->save();
        $page = Livewire::test(PricingForeignRetailCalculations::class);
        $expected = (2 * 400 + 10 * 400) / (460 + 1000);
        $this->assertEqualsWithDelta($expected, $page->instance()->averageCostBasedMultiplier, 0.0000001);
        $this->assertEqualsWithDelta(12 / ((float) $first->base_price + 20), $page->instance()->averageRetailMultiplier, 0.0000001);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 3,29"', false);
        $page->sortTable('cost_based_multiplier', 'asc')->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $page->sortTable('cost_based_multiplier', 'desc')->assertCanSeeTableRecords([$second, $first], inOrder: true);
        $page->searchTableColumns(['cost_based_multiplier' => '4'])
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        $this->assertSame(4.0, $page->instance()->averageCostBasedMultiplier);
        $page->callAction('decimalPlaces', ['multiplier' => 6, 'cost_based_multiplier' => 3])->assertHasNoErrors();
        $page->assertSee('data-pricing-average-multiplier="Átlag: 4,00"', false);
        $column = $page->instance()->getTable()->getColumn('cost_based_multiplier')->record($second);
        $this->assertSame('4,000', $column->formatState($column->getState()));
        $export = new AdminTableExport($page->instance()->getTableQueryForExport(), [$column]);
        $this->assertSame([4.0], $export->map($second));
        $this->assertSame(['A' => '#,##0.000'], $page->instance()->pricingDecimalExportFormats([$column]));
        $page->searchTableColumns(['product.model_code' => 'NO-SUCH-MODEL']);
        $this->assertNull($page->instance()->averageCostBasedMultiplier);
        $page->assertSee('data-pricing-average-multiplier="Átlag: -"', false);
    }

    public function test_cost_multiplier_average_covers_all_filtered_pages_and_avoids_per_row_queries(): void
    {
        $czk = $this->makeList('CZK', 16);
        $this->calculate($czk);
        $this->calculate();
        $this->source->update(['base_price' => 400]);
        $first = $this->retail();
        $first->update(['calculated_price' => 2]);
        for ($index = 1; $index <= 50; $index++) {
            $color = Color::create(['product_id' => $first->product_id, 'code' => 'C'.$index, 'name_hu' => 'Szín '.$index, 'active' => true]);
            $source = $this->source->replicate();
            $source->color_id = $color->id;
            $source->base_price = 300;
            $source->save();
            $copy = $first->replicate();
            $copy->color_id = $color->id;
            $copy->source_retail_row_id = $source->id;
            $copy->calculated_price = 3;
            $copy->save();
        }
        $page = Livewire::test(PricingForeignRetailCalculations::class)->set('tableRecordsPerPage', 50);
        $this->assertSame(50, $page->instance()->getTableRecords()->count());
        $this->assertEqualsWithDelta((2 * 400 + 50 * 3 * 400) / (400 + 50 * 300), $page->instance()->averageCostBasedMultiplier, 0.0000001);
        $records = $page->instance()->getTableRecords();
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($records as $record) {
            $this->assertNotNull($record->cost_based_multiplier);
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $page->call('selectRetailCurrency', 'CZK');
        $this->assertEqualsWithDelta((float) $this->retail($czk)->final_price * 16 / 400, $page->instance()->averageCostBasedMultiplier, 0.0000001);
        $page->call('selectRetailCurrency', 'EUR')->searchTableColumns(['color.name_hu' => 'Szín 50']);
        $this->assertSame(4.0, $page->instance()->averageCostBasedMultiplier);
    }

    public function test_cost_multiplier_handles_invalid_inputs_and_mismatched_sources_consistently_in_sql(): void
    {
        $this->calculate();
        foreach ([[null, 400], [0, 400], [-10, 400], [460, null], [460, 0], [460, -400]] as [$cost, $rate]) {
            $this->source->update(['base_price' => $cost]);
            $row = $this->retail();
            $row->update(['retail_exchange_rate' => $rate]);
            $this->assertNull($row->cost_based_multiplier);
            $this->assertNull(PricingCalculationRow::whereKey($row->id)
                ->selectRaw(PricingCalculationRow::costBasedMultiplierSql().' AS sql_multiplier')->firstOrFail()->sql_multiplier);
        }
        $this->source->update(['base_price' => 460, 'color_id' => Color::create([
            'product_id' => $this->source->product_id, 'code' => 'MISMATCH', 'name_hu' => 'Más szín', 'active' => true,
        ])->id]);
        $row = $this->retail();
        $row->update(['retail_exchange_rate' => 400]);
        $this->assertNull($row->cost_based_multiplier);
        $this->assertNull(PricingCalculationRow::whereKey($row->id)
            ->selectRaw(PricingCalculationRow::costBasedMultiplierSql().' AS sql_multiplier')->firstOrFail()->sql_multiplier);
        $row->update(['source_retail_row_id' => null]);
        $row->unsetRelation('retailCostSource');
        $this->assertNull($row->cost_based_multiplier);
        $this->assertNull(Livewire::test(PricingForeignRetailCalculations::class)->instance()->averageCostBasedMultiplier);
    }

    public function test_manual_edit_refreshes_cost_multiplier_and_its_average_and_huf_hides_the_new_column(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingForeignRetailCalculations::class);
        $page->call('updateTableColumnState', 'manual_price', (string) $this->retail()->id, '3.95')->assertHasNoErrors();
        $this->assertEqualsWithDelta(3.95 * 400 / 460, $page->instance()->averageCostBasedMultiplier, 0.0000001);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 3,43"', false);
        $this->assertTrue(Livewire::test(PricingHufRetailCalculations::class)->instance()->getTable()->getColumn('cost_based_multiplier')->isHidden());
    }

    public function test_cost_multiplier_average_is_scoped_to_the_selected_project_and_target_list(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingForeignRetailCalculations::class);
        $other = $this->project->replicate();
        $other->name = 'Másik árprojekt';
        $other->save();
        $source = $this->source->replicate();
        $source->pricing_project_id = $other->id;
        $source->base_price = 100;
        $source->save();
        $foreign = $this->retail()->replicate();
        $foreign->pricing_project_id = $other->id;
        $foreign->source_retail_row_id = $source->id;
        $foreign->calculated_price = 2;
        $foreign->save();
        $page->call('$refresh');
        $this->assertEqualsWithDelta(4.95 * 400 / 460, $page->instance()->averageCostBasedMultiplier, 0.0000001);
        $page->call('selectPricingProject', $other->id);
        $this->assertSame(8.0, $page->instance()->averageCostBasedMultiplier);
        $secondList = $this->makeSameCurrencyTarget('OTHER-EUR');
        $copy = $foreign->replicate();
        $copy->price_list_id = $secondList->id;
        $copy->calculated_price = 3;
        $copy->save();
        $page->call('$refresh');
        $this->assertSame(10.0, $page->instance()->averageCostBasedMultiplier);
        $page->filterTable('price_list_id', $this->list->id);
        $this->assertSame(8.0, $page->instance()->averageCostBasedMultiplier);
    }

    private function makeHufSource(string $code): PriceList
    {
        $list = $this->hufList->replicate();
        $list->code = $code;
        $list->save();
        app(HufRetailCalculationService::class)->recalculate($this->project, $list);

        return $list;
    }

    private function makeSameCurrencyTarget(string $code): PriceList
    {
        $list = $this->list->replicate();
        $list->code = $code;
        $list->save();
        foreach ([PricingParameter::class, PricingRoundingRule::class] as $class) {
            foreach ($class::where('price_list_id', $this->list->id)->get() as $setting) {
                $copy = $setting->replicate();
                $copy->price_list_id = $list->id;
                $copy->save();
            }
        }

        return $list;
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
