<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Exports\PartnerOrderMatrixExport;
use App\Exports\PartnerOrderSummaryMatrixExport;
use App\Filament\Pages\PriceListImport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PricingCalculationRows\Pages\EditPricingCalculationRow;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Models\AdminUiPreference;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\DerivedPriceRowWriter;
use App\Services\Pricing\DistributorCalculationService;
use App\Services\Pricing\DistributorPriceReviewService;
use App\Services\Pricing\ForeignRetailCalculationService;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\WholesaleCalculationService;
use App\Services\Pricing\WholesalePriceReviewService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DistributorCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PriceList $whole;

    private PriceList $target;

    private PriceList $retail;

    private PricingParameter $discount;

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
        $eur = Currency::create(['code' => 'EUR', 'name_hu' => 'Euró', 'active' => true]);
        $retailHuf = PriceList::create(['code' => 'RETAIL_HU', 'name_hu' => 'Magyar kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $this->retail = PriceList::create(['code' => 'RETAIL_EU', 'name_hu' => 'Euró kisker', 'type' => 'retail', 'currency_id' => $eur->id, 'active' => true]);
        $this->whole = PriceList::create(['code' => 'WHOLE_EU', 'name_hu' => 'Euró nagyker', 'type' => 'wholesale', 'currency_id' => $eur->id, 'retail_price_list_id' => $this->retail->id, 'active' => true]);
        $this->target = PriceList::create(['code' => 'DIST_EU', 'name_hu' => 'Euró disztribútor', 'type' => 'distributor', 'currency_id' => $eur->id, 'source_wholesale_price_list_id' => $this->whole->id, 'active' => true]);
        PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id, 'currency_id' => $huf->id, 'price_type' => 'cost', 'calculated_price' => 460]);
        ExchangeRate::create(['season_id' => $season->id, 'currency_id' => $eur->id, 'rate_to_huf' => 400, 'active' => true]);
        foreach (['retail_multiplier' => 4.7, 'country_multiplier' => 1.1, 'wholesale_margin' => 2.5, 'distributor_discount' => 20] as $type => $value) {
            $parameter = PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => $type, 'scope_type' => 'global', 'value' => $value, 'active' => true]);
            if ($type === 'distributor_discount') {
                $this->discount = $parameter;
            }
        }
        foreach ([['retail', $retailHuf, 1000, -10], ['retail', $this->retail, 1, -0.05], ['wholesale', $this->whole, 0.01, 0], ['distributor', $this->target, 0.01, 0]] as [$type, $list, $step, $adjustment]) {
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $list->code, 'price_type' => $type,
                'price_list_id' => $list->id, 'currency_id' => $list->currency_id, 'round_to' => $step, 'mode' => 'nearest', 'adjustment' => $adjustment, 'active' => true]);
        }
        app(HufRetailCalculationService::class)->recalculate($this->project, $retailHuf);
        app(ForeignRetailCalculationService::class)->recalculate($this->project, $retailHuf, $this->retail);
        app(WholesaleCalculationService::class)->recalculate($this->project);
    }

    private function source(): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_type', 'wholesale')->where('price_list_id', $this->whole->id)->firstOrFail();
    }

    private function row(?PriceList $list = null): PricingCalculationRow
    {
        return PricingCalculationRow::where('price_type', 'distributor')->where('price_list_id', ($list ?? $this->target)->id)->firstOrFail();
    }

    private function calculate(): array
    {
        return app(DistributorCalculationService::class)->recalculate($this->project, $this->target->id);
    }

    public function test_net_wholesale_final_price_is_discounted_and_rounded_independently(): void
    {
        $this->assertSame(1, $this->calculate()['created']);
        $this->assertSame('1.9800', $this->row()->base_price);
        $this->assertSame('20.000000', $this->row()->distributor_discount_percent);
        $this->assertSame('1.5840', $this->row()->unrounded_price);
        $this->assertSame('1.5800', $this->row()->final_price);
        $this->assertSame($this->source()->id, $this->row()->source_wholesale_row_id);
        $this->assertSame(0, $this->calculate()['created']);
        app(WholesalePriceReviewService::class)->setManualPrice($this->source(), 2.5);
        $this->calculate();
        $this->assertSame('2.0000', $this->row()->final_price);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_zero_discount_is_valid_but_missing_negative_and_full_discount_are_not(): void
    {
        $this->discount->update(['value' => 0]);
        $this->calculate();
        $this->assertSame('1.9800', $this->row()->final_price);
        foreach ([-1, 100, 101] as $invalid) {
            $this->discount->update(['value' => $invalid]);
            $this->assertSame(1, $this->calculate()['skipped']);
            $this->assertSame('1.9800', $this->row()->final_price);
            $this->assertTrue($this->row()->requires_review);
        }
        $this->discount->update(['active' => false]);
        $this->assertSame(1, $this->calculate()['skipped']);
    }

    public function test_approved_distributor_price_is_retained_and_can_be_reapproved_after_source_change(): void
    {
        $this->calculate();
        $review = app(DistributorPriceReviewService::class);
        $review->approveCurrentPrice($this->row());
        app(WholesalePriceReviewService::class)->setManualPrice($this->source(), 1.5);
        $this->assertTrue($this->row()->requires_review);
        try {
            $review->approveCurrentPrice($this->row());
            $this->fail('A changed source requires recalculation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('újraszámítást', $exception->getMessage());
        }
        $this->calculate();
        $this->assertSame('1.5800', $this->row()->final_price);
        $this->assertSame('1.2000', $this->row()->calculated_price);
        $this->assertTrue($this->row()->requires_review);
        $review->approveCurrentPrice($this->row());
        $this->assertFalse($this->row()->requires_review);
        $review->acceptCalculatedPrice($this->row());
        $this->assertSame('1.2000', $this->row()->final_price);
    }

    public function test_discount_changes_require_review_even_when_the_rounded_price_is_unchanged(): void
    {
        $this->calculate();
        $review = app(DistributorPriceReviewService::class);
        $review->approveCurrentPrice($this->row());
        $this->discount->update(['value' => '20.000001']);
        $this->calculate();
        $this->assertSame('1.5800', $this->row()->calculated_price);
        $this->assertSame('20.000001', $this->row()->distributor_discount_percent);
        $this->assertTrue($this->row()->requires_review);
        $review->approveCurrentPrice($this->row());
        $this->assertFalse($this->row()->requires_review);
    }

    public function test_cost_and_retail_changes_propagate_to_distributor_without_price_changes(): void
    {
        $this->calculate();
        $before = $this->row()->final_price;
        PricingCalculationRow::where('price_type', 'cost')->firstOrFail()->update(['calculated_price' => 500]);
        $this->assertTrue($this->source()->requires_review);
        $this->assertTrue($this->row()->requires_review);
        $this->assertSame(1, $this->calculate()['skipped']);
        $this->assertSame($before, $this->row()->final_price);
    }

    public function test_foreign_currency_and_wrong_source_list_are_rejected_before_calculation(): void
    {
        $this->target->update(['source_wholesale_price_list_id' => $this->retail->id]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('EUR nagyker');
        $this->calculate();
    }

    public function test_deleting_the_source_preserves_price_and_flags_review(): void
    {
        $this->calculate();
        $this->source()->delete();
        $this->assertNull($this->row()->source_wholesale_row_id);
        $this->assertTrue($this->row()->requires_review);
        $this->assertSame('1.5800', $this->row()->final_price);
    }

    public function test_distributor_workspace_is_separate_from_both_wholesale_currencies(): void
    {
        $wholesale = Livewire::test(PricingWholesaleCalculations::class);
        $this->assertNull($wholesale->instance()->getTable()->getColumn('distributor_final_price'));
        $wholesale->assertActionDoesNotExist('calculateDistributor')->call('selectWholesaleCurrency', 'EUR');
        $this->assertNull($wholesale->instance()->getTable()->getColumn('distributor_final_price'));
        $wholesale->assertDontSee('data-distributor-price-list-panel', false);
        $page = Livewire::test(PricingDistributorCalculations::class)->assertSuccessful();
        $page->assertActionDoesNotExist('calculateWholesale')->assertActionDoesNotExist('calculateAllWholesale')
            ->assertTableActionDoesNotExist('approveRetail')->assertTableBulkActionDoesNotExist('approveRetailBulk');
        $this->assertNull($page->instance()->getTable()->getColumn('manual_price'));
        $this->assertSame($this->target->id, $page->instance()->distributorPriceListId);
        $page->assertActionVisible('calculateDistributor')->callAction('calculateDistributor')->assertHasNoErrors();
        $this->assertSame('1.5800', $this->row()->final_price);
        $page->call('setEurVatPercent', 20);
        $this->assertEqualsWithDelta(4.95 / 1.2 / 1.58, $page->instance()->averageDistributorNetNetMultiplier, 0.0000001);
        $page->assertSee('data-pricing-average-multiplier="Átlag: 2,61"', false);
    }

    public function test_manual_edit_and_child_approval_do_not_touch_wholesale_price_or_approval(): void
    {
        $this->calculate();
        app(WholesalePriceReviewService::class)->approveCurrentPrice($this->source());
        $before = $this->source()->getAttributes();
        $page = Livewire::test(PricingDistributorCalculations::class);
        $page->call('updateTableColumnState', 'manual_price', (string) $this->source()->id, '99');
        $page->call('updateTableColumnState', 'final_price', (string) $this->source()->id, '99');
        $this->assertSame($before, $this->source()->getAttributes());
        $page->call('updateTableColumnState', 'distributor_manual_price', (string) $this->source()->id, '1.4')->assertHasNoErrors();
        $this->assertSame('1.4000', $this->row()->final_price);
        $this->assertSame($before, $this->source()->getAttributes());
        $page->callTableAction('approveDistributor', $this->source(), ['approval_status' => 'approved'])->assertHasNoErrors();
        $this->assertSame('modified_approved', $this->row()->status);
        $this->assertSame($before, $this->source()->getAttributes());
        $page->mountTableAction('distributorDetails', $this->source());
        $this->assertStringContainsString('Disztribútori kedvezmény', $page->instance()->getMountedAction()->getModalContent()->render());
    }

    public function test_multiple_distributor_lists_require_selection_and_never_duplicate_wholesale_rows(): void
    {
        $other = $this->target->replicate();
        $other->code = 'DIST_OTHER';
        $other->save();
        $rule = PricingRoundingRule::where('price_list_id', $this->target->id)->firstOrFail()->replicate();
        $rule->price_list_id = $other->id;
        $rule->save();
        app(DistributorCalculationService::class)->recalculate($this->project);
        $page = Livewire::test(PricingDistributorCalculations::class);
        $this->assertNull($page->instance()->distributorPriceListId);
        $page->assertActionHidden('calculateDistributor')->call('selectDistributorPriceList', $this->target->id);
        $this->assertSame(1, $page->instance()->getTableRecords()->count());
        $page->call('selectDistributorPriceList', $other->id);
        $this->assertSame(1, $page->instance()->getTableRecords()->count());
        $this->assertSame($other->id, Livewire::test(PricingDistributorCalculations::class)->instance()->distributorPriceListId);
        $this->assertSame(2, PricingCalculationRow::where('price_type', 'distributor')->count());
    }

    public function test_child_values_can_be_filtered_sorted_and_exported_as_numbers_without_row_queries(): void
    {
        $this->calculate();
        $page = Livewire::test(PricingDistributorCalculations::class)->call('setEurVatPercent', 20);
        $page->sortTable('distributor_final_price')->searchTableColumns(['distributor_final_price' => '1,58'])
            ->assertHasNoErrors()->assertCanSeeTableRecords([$this->source()]);
        $table = $page->instance()->getTable();
        $columns = [$table->getColumn('distributor_discount_percent'), $table->getColumn('distributor_final_price'), $table->getColumn('distributor_net_net_multiplier')];
        $export = new AdminTableExport($page->instance()->getTableQueryForExport(), $columns);
        $record = $page->instance()->getTableRecords()->first();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $values = $export->map($record);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([20.0, 1.58], array_slice($values, 0, 2));
        $this->assertEqualsWithDelta(4.95 / 1.2 / 1.58, $values[2], 0.0000001);
        $this->assertCount(0, $queries);
        $page->callAction('decimalPlaces', ['distributor_final_price' => 2])->assertHasNoErrors();
        $this->assertSame(['A' => '#,##0.00'], $page->instance()->pricingDecimalExportFormats([$columns[1]]));
        $page->searchTableColumns(['distributor_final_price' => 'NINCS']);
        $this->assertNull($page->instance()->averageDistributorNetNetMultiplier);
    }

    public function test_personal_vat_changes_comparison_only_and_is_not_an_approval_input(): void
    {
        $this->calculate();
        app(DistributorPriceReviewService::class)->approveCurrentPrice($this->row());
        $before = $this->row()->getAttributes();
        $page = Livewire::test(PricingDistributorCalculations::class);
        $this->assertNull($page->instance()->averageDistributorNetNetMultiplier);
        $page->call('setEurVatPercent', 25);
        $this->assertEqualsWithDelta(4.95 / 1.25 / 1.58, $page->instance()->averageDistributorNetNetMultiplier, 0.0000001);
        $this->assertSame($before, $this->row()->getAttributes());
    }

    public function test_archive_and_read_only_users_cannot_write_distributor_prices(): void
    {
        $this->calculate();
        $reader = User::factory()->create();
        foreach (['View:PricingDistributorCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($reader);
        $page = Livewire::test(PricingDistributorCalculations::class);
        $page->assertActionHidden('calculateDistributor')->assertTableBulkActionHidden('approveDistributorBulk');
        $this->assertTrue($page->instance()->getTable()->getColumn('distributor_manual_price')->record($this->source())->isDisabled());
        $this->project->update(['status' => 'closed']);
        $page->call('setPricingArchive', true)->assertActionHidden('calculateDistributor')->assertTableBulkActionHidden('approveDistributorBulk');
    }

    public function test_price_list_form_creates_valid_eur_distributor_sources_and_rejects_huf(): void
    {
        $data = ['code' => 'DIST_FORM', 'name_hu' => 'Disztribútor', 'type' => 'distributor', 'currency_id' => $this->whole->currency_id,
            'source_wholesale_price_list_id' => $this->whole->id, 'retail_price_list_id' => $this->retail->id, 'active' => true];
        Livewire::test(CreatePriceList::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('price_lists', ['code' => 'DIST_FORM', 'source_wholesale_price_list_id' => $this->whole->id,
            'retail_price_list_id' => $this->retail->id]);
        $data['code'] = 'DIST_BAD';
        $data['currency_id'] = Currency::where('code', 'HUF')->firstOrFail()->id;
        Livewire::test(CreatePriceList::class)->fillForm($data)->call('create')->assertHasFormErrors(['currency_id']);
        $this->assertDatabaseMissing('price_lists', ['code' => 'DIST_BAD']);
    }

    public function test_import_resolves_forward_wholesale_links_and_preserves_omitted_existing_sources(): void
    {
        $page = new class extends PriceListImport
        {
            public function headers(array $rows): void
            {
                $this->sheets = ['price_lists' => $rows, 'price_list_items' => []];
                $this->validateRows();
                $this->importPriceLists($rows);
            }
        };
        $page->headers([
            ['code' => 'DIST_FORWARD', 'name_hu' => 'Disztribútor', 'type' => 'distributor', 'currency_code' => 'EUR', 'active' => 1,
                'source_wholesale_price_list_code' => 'WHOLE_FORWARD', 'retail_price_list_code' => 'RETAIL_FORWARD'],
            ['code' => 'WHOLE_FORWARD', 'name_hu' => 'Nagyker', 'type' => 'wholesale', 'currency_code' => 'EUR', 'active' => 1, 'retail_price_list_code' => 'RETAIL_EU'],
            ['code' => 'RETAIL_FORWARD', 'name_hu' => 'Kisker', 'type' => 'retail', 'currency_code' => 'EUR', 'active' => 1],
        ]);
        $this->assertSame([], $page->rowValidationErrors);
        $source = PriceList::where('code', 'WHOLE_FORWARD')->firstOrFail();
        $this->assertSame($source->id, PriceList::where('code', 'DIST_FORWARD')->firstOrFail()->source_wholesale_price_list_id);
        $this->assertSame(PriceList::where('code', 'RETAIL_FORWARD')->value('id'), PriceList::where('code', 'DIST_FORWARD')->value('retail_price_list_id'));
        $this->target->update(['retail_price_list_id' => $this->retail->id]);
        $page->headers([['code' => 'DIST_EU', 'name_hu' => 'Frissített név', 'type' => 'distributor', 'currency_code' => 'EUR', 'active' => 1]]);
        $this->assertSame($this->whole->id, $this->target->fresh()->source_wholesale_price_list_id);
        $this->assertSame($this->retail->id, $this->target->fresh()->retail_price_list_id);
        $page->headers([['code' => 'DIST_EU', 'name_hu' => 'Frissített név', 'type' => 'distributor', 'currency_code' => 'EUR', 'active' => 1,
            'retail_price_list_code' => '']]);
        $this->assertNull($this->target->fresh()->retail_price_list_id);
        $this->assertSame($this->whole->id, $this->target->fresh()->source_wholesale_price_list_id);
        $page->headers([['code' => 'DIST_INVALID', 'name_hu' => 'Hibás', 'type' => 'distributor', 'currency_code' => 'HUF', 'active' => 1]]);
        $this->assertDatabaseMissing('price_lists', ['code' => 'DIST_INVALID']);
    }

    public function test_editing_a_legacy_wholesale_list_to_distributor_preserves_both_links(): void
    {
        $legacy = PriceList::create(['code' => 'LEGACY_DIST', 'name_hu' => 'Régi disztribútor', 'type' => 'wholesale',
            'currency_id' => $this->whole->currency_id, 'retail_price_list_id' => $this->retail->id, 'active' => true]);
        $page = Livewire::test(EditPriceList::class, ['record' => $legacy->getRouteKey()]);
        $page->set('data.type', 'distributor')
            ->assertSet('data.retail_price_list_id', $this->retail->id)
            ->assertFormFieldIsVisible('retail_price_list_id')
            ->assertFormFieldIsVisible('source_wholesale_price_list_id')
            ->fillForm(['source_wholesale_price_list_id' => $this->whole->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('distributor', $legacy->fresh()->type);
        $this->assertSame($this->retail->id, $legacy->fresh()->retail_price_list_id);
        $this->assertSame($this->whole->id, $legacy->fresh()->source_wholesale_price_list_id);
        // Ordinary model saves must also retain the portal's independent retail link.
        $legacy->fresh()->update(['name_hu' => 'Új név']);
        $this->assertSame($this->retail->id, $legacy->fresh()->retail_price_list_id);
    }

    public function test_distributor_discount_uses_wholesale_even_with_a_different_display_retail_list(): void
    {
        $display = PriceList::create(['code' => 'DISPLAY_RETAIL', 'name_hu' => 'Megjelenítési kisker', 'type' => 'retail',
            'currency_id' => $this->whole->currency_id, 'active' => true]);
        $this->target->update(['retail_price_list_id' => $display->id]);
        // A wholesale multiplier exists only for the WHOLE_EU source, not the distributor target.
        PricingParameter::where('parameter_type', 'wholesale_margin')->update(['price_list_id' => $this->whole->id]);
        $this->assertSame(1, $this->calculate()['created']);
        $this->assertSame('1.9800', $this->row()->base_price);
        $this->assertSame('1.5800', $this->row()->final_price);
        $this->assertSame($this->whole->id, $this->row()->sourceWholesaleRow->price_list_id);
        $this->assertSame($display->id, $this->target->fresh()->retail_price_list_id);
    }

    public function test_type_conversion_preserves_partner_shared_page_and_excel_prices(): void
    {
        $this->target->update(['type' => 'wholesale', 'retail_price_list_id' => $this->retail->id]);
        $product = $this->source()->product;
        Color::create(['product_id' => $product->id, 'code' => 'BLACK', 'name_hu' => 'Fekete', 'active' => true]);
        foreach ([[$this->target, 1.45], [$this->retail, 4.95]] as [$list, $price]) {
            PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id,
                'season_id' => $this->project->season_id, 'net_price' => $price]);
        }
        $published = DB::table('price_list_items')->orderBy('id')->get()->toArray();
        $order = new Order(['season_id' => $this->project->season_id, 'price_list_id' => $this->target->id,
            'reference_number' => 'TEST-DISTRIBUTOR']);
        $portal = new class extends CatalogGroupOrder
        {
            public function displayedProduct(Product $product, int $priceListId): array
            {
                $this->setDisplayPriceList($priceListId);

                return $this->formatProduct($product);
            }
        };
        $portal->order = $order;
        $beforePortal = $portal->displayedProduct($product, $this->target->id);
        $beforeExport = (new PartnerOrderMatrixExport($order))->array();
        $beforeSummary = (new PartnerOrderSummaryMatrixExport(new Collection([$order])))->array();

        $this->target->update(['type' => 'distributor', 'source_wholesale_price_list_id' => $this->whole->id]);

        $this->assertSame($beforePortal, $portal->displayedProduct($product, $this->target->id));
        $this->assertSame(1.45, $beforePortal['price']);
        $this->assertSame(4.95, $beforePortal['retail_price']);
        $portal->sharedMode = true;
        $this->assertSame($beforePortal, $portal->displayedProduct($product, $this->target->id));
        $afterExport = (new PartnerOrderMatrixExport($order))->array();
        $this->assertSame($beforeExport, $afterExport);
        $this->assertSame($beforeSummary, (new PartnerOrderSummaryMatrixExport(new Collection([$order])))->array());
        $exportRow = collect($afterExport)->first(fn ($row) => ($row[2] ?? null) === $product->model_code);
        $this->assertNotNull($exportRow);
        $this->assertSame(1.45, $exportRow[6]);
        $this->assertSame(4.95, $exportRow[7]);
        $this->assertEquals($published, DB::table('price_list_items')->orderBy('id')->get()->toArray());
        $this->calculate();
        $this->assertSame('1.5800', $this->row()->final_price);
        $this->assertEquals($published, DB::table('price_list_items')->orderBy('id')->get()->toArray());
        $this->assertSame($beforePortal, $portal->displayedProduct($product, $this->target->id));
    }

    public function test_migration_adds_nullable_source_and_discount_fields_without_rewriting_prices(): void
    {
        $this->assertTrue(Schema::hasColumn('price_lists', 'source_wholesale_price_list_id'));
        $this->assertTrue(Schema::hasColumn('pricing_calculation_rows', 'source_wholesale_row_id'));
        $this->assertTrue(Schema::hasColumn('pricing_calculation_rows', 'distributor_discount_percent'));
        $this->assertSame('1.9800', $this->source()->final_price);
        $this->assertNull($this->source()->source_wholesale_row_id);
    }

    private function anotherTarget(string $code): PriceList
    {
        $other = $this->target->replicate();
        $other->code = $code;
        $other->save();
        $rule = PricingRoundingRule::where('price_list_id', $this->target->id)->firstOrFail()->replicate();
        $rule->price_list_id = $other->id;
        $rule->save();

        return $other;
    }

    public function test_bulk_distributor_approval_uses_selected_parent_rows_without_changing_wholesale(): void
    {
        $this->calculate();
        $before = $this->source()->getAttributes();
        $page = Livewire::test(PricingDistributorCalculations::class);
        $page->callTableBulkAction('approveDistributorBulk', [$this->source()], ['approval_status' => 'approved'])
            ->assertHasNoErrors()->assertNotified('Disztribútori tömeges jóváhagyás kész');
        $this->assertSame('approved', $this->row()->status);
        $this->assertSame($before, $this->source()->getAttributes());
    }

    public function test_an_invalid_target_prevents_partial_batch_writes(): void
    {
        $other = $this->anotherTarget('INVALID_DIST');
        $other->update(['source_wholesale_price_list_id' => $this->retail->id]);
        try {
            app(DistributorCalculationService::class)->recalculate($this->project);
            $this->fail('Invalid links must be detected before the first write.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('EUR nagyker', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_type', 'distributor')->count());
    }

    public function test_unexpected_batch_failure_rolls_back_previously_created_distributor_prices(): void
    {
        $this->anotherTarget('SECOND_DIST');
        $writer = app(DerivedPriceRowWriter::class);
        $mock = $this->mock(DerivedPriceRowWriter::class);
        $calls = 0;
        $mock->shouldReceive('save')->andReturnUsing(function (...$arguments) use ($writer, &$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Unexpected failure');
            }

            return $writer->save(...$arguments);
        });
        try {
            app(DistributorCalculationService::class)->recalculate($this->project);
            $this->fail('The transaction must roll back all prices.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected failure', $exception->getMessage());
        }
        $this->assertSame(0, PricingCalculationRow::where('price_type', 'distributor')->count());
    }

    public function test_selecting_a_target_scopes_rows_to_its_wholesale_source(): void
    {
        $otherWhole = $this->whole->replicate();
        $otherWhole->code = 'WHOLE_SECOND';
        $otherWhole->save();
        $rule = PricingRoundingRule::where('price_list_id', $this->whole->id)->firstOrFail()->replicate();
        $rule->price_list_id = $otherWhole->id;
        $rule->save();
        app(WholesaleCalculationService::class)->recalculate($this->project);
        $other = $this->anotherTarget('DIST_SECOND');
        $other->update(['source_wholesale_price_list_id' => $otherWhole->id]);
        app(DistributorCalculationService::class)->recalculate($this->project);
        $page = Livewire::test(PricingDistributorCalculations::class);
        $this->assertSame(0, $page->instance()->getTableRecords()->count());
        $page->call('selectDistributorPriceList', $other->id);
        $records = $page->instance()->getTableRecords();
        $this->assertCount(1, $records);
        $this->assertSame($otherWhole->id, $records->first()->price_list_id);
        $this->assertSame($other->id, $page->instance()->distributorRow($records->first())->price_list_id);
    }

    public function test_average_uses_all_filtered_rows_not_only_the_current_page(): void
    {
        $this->calculate();
        $first = $this->source();
        $first->update(['base_price' => 2]);
        $child = $this->row();
        $child->update(['calculated_price' => 1]);
        for ($index = 1; $index <= 50; $index++) {
            $color = Color::create(['product_id' => $first->product_id, 'code' => 'C'.$index, 'name_hu' => 'Szín '.$index, 'active' => true]);
            $source = $first->replicate();
            $source->color_id = $color->id;
            $source->base_price = $index === 50 ? 6 : 4;
            $source->save();
            $copy = $child->replicate();
            $copy->color_id = $color->id;
            $copy->source_wholesale_row_id = $source->id;
            $copy->calculated_price = $index === 50 ? 2 : 1;
            $copy->save();
        }
        $page = Livewire::test(PricingDistributorCalculations::class)->call('setEurVatPercent', 20)->set('tableRecordsPerPage', 50);
        $this->assertSame(50, $page->instance()->getTableRecords()->count());
        $this->assertEqualsWithDelta(201 / 51 / 1.2, $page->instance()->averageDistributorNetNetMultiplier, 0.0000001);
        $page->searchTableColumns(['color.name_hu' => 'Szín 50']);
        $this->assertEqualsWithDelta(3 / 1.2, $page->instance()->averageDistributorNetNetMultiplier, 0.0000001);
    }

    public function test_inactive_distributor_lists_remain_visible_in_archive_but_cannot_be_modified(): void
    {
        $this->calculate();
        $this->target->update(['active' => false]);
        $this->project->update(['status' => 'closed']);
        $page = Livewire::test(PricingDistributorCalculations::class)->call('setPricingArchive', true);
        $this->assertSame($this->target->id, $page->instance()->selectedDistributorPriceList->id);
        $page->assertCanSeeTableRecords([$this->source()])->assertActionHidden('calculateDistributor')
            ->assertTableActionDisabled('approveDistributor', $this->source());
        $this->assertTrue($page->instance()->getTable()->getColumn('distributor_manual_price')->record($this->source())->isDisabled());
    }

    public function test_failed_wholesale_recalculation_marks_its_distributor_dependents(): void
    {
        $this->calculate();
        PricingRoundingRule::where('price_type', 'wholesale')->update(['active' => false]);
        $this->assertSame(1, app(WholesaleCalculationService::class)->recalculate($this->project)['skipped']);
        $this->assertTrue($this->row()->requires_review);
        $this->assertSame('1.5800', $this->row()->final_price);
    }

    public function test_failed_retail_recalculation_and_price_list_changes_propagate_review(): void
    {
        $this->calculate();
        $sourceRetail = $this->source()->sourceRetailRow;
        $hufList = $sourceRetail->sourceRetailRow->priceList;
        PricingRoundingRule::where('price_list_id', $this->retail->id)->update(['active' => false]);
        $this->assertSame(1, app(ForeignRetailCalculationService::class)->recalculate($this->project, $hufList, $this->retail)['skipped']);
        $this->assertTrue($this->source()->requires_review);
        $this->assertTrue($this->row()->requires_review);
        $this->assertSame('1.5800', $this->row()->final_price);
    }

    public function test_huf_and_other_project_record_ids_cannot_be_used_for_distributor_edits(): void
    {
        $this->calculate();
        $source = $this->source();
        $project = $this->project->replicate();
        $project->name = 'Másik projekt';
        $project->save();
        $other = $source->replicate();
        $other->pricing_project_id = $project->id;
        $other->save();
        $page = Livewire::test(PricingDistributorCalculations::class);
        $page->call('updateTableColumnState', 'distributor_manual_price', (string) $other->id, '10');
        $this->assertSame('1.5800', $this->row()->final_price);
        $huf = $source->replicate();
        $huf->currency_id = Currency::where('code', 'HUF')->value('id');
        $huf->save();
        $page->call('updateTableColumnState', 'distributor_manual_price', (string) $huf->id, '10');
        $this->assertSame('1.5800', $this->row()->final_price);
    }

    public function test_generic_record_form_cannot_bypass_wholesale_or_distributor_review(): void
    {
        $this->calculate();
        foreach ([$this->source(), $this->row()] as $row) {
            Livewire::test(EditPricingCalculationRow::class, ['record' => $row->id])
                ->fillForm(['manual_price' => 99, 'status' => 'approved'])->call('save')->assertHasNoFormErrors();
            $this->assertNull($row->fresh()->manual_price);
            $this->assertSame('calculated', $row->fresh()->status);
        }
    }

    public function test_wholesale_and_distributor_page_permissions_are_independent(): void
    {
        foreach ([PricingWholesaleCalculations::class, PricingDistributorCalculations::class] as $allowed) {
            $user = User::factory()->create();
            $permission = 'View:'.class_basename($allowed);
            $user->assignRole(Role::findOrCreate('reader_'.class_basename($allowed), 'web'));
            foreach ([$permission, 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow'] as $name) {
                $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }
            $this->actingAs($user);
            $this->assertSame($allowed === PricingWholesaleCalculations::class, PricingWholesaleCalculations::canAccess());
            $this->assertSame($allowed === PricingDistributorCalculations::class, PricingDistributorCalculations::canAccess());
            $this->get('/admin/pricing-wholesale-calculations')->assertStatus($allowed === PricingWholesaleCalculations::class ? 200 : 403);
            $this->get('/admin/pricing-distributor-calculations')->assertStatus($allowed === PricingDistributorCalculations::class ? 200 : 403);
        }
        $pages = FilamentShield::getPages();
        $this->assertArrayHasKey('View:PricingDistributorCalculations', $pages[PricingDistributorCalculations::class]['permissions']);
    }

    public function test_distributor_preferences_are_separate_and_preserve_legacy_widths_precision_and_personal_vat(): void
    {
        foreach (['column_widths' => ['distributor-final-price' => 115], 'decimal_places' => ['distributor_final_price' => 2]] as $suffix => $value) {
            AdminUiPreference::create(['user_id' => auth()->id(), 'preference_key' => 'pricing_wholesale_calculation_'.$suffix, 'value' => $value]);
        }
        $wholesale = Livewire::test(PricingWholesaleCalculations::class)->call('setEurVatPercent', 19);
        $distributor = Livewire::test(PricingDistributorCalculations::class);
        $this->assertEquals(19, $distributor->instance()->eurVatPercent);
        $this->assertSame(['distributor-final-price' => 115], $distributor->instance()->pricingColumnWidths);
        $this->assertSame(2, $distributor->instance()->pricingDecimalPlacesFor('distributor_final_price'));
        $this->assertNotSame($wholesale->instance()->pricingColumnStorageKey(), $distributor->instance()->pricingColumnStorageKey());
        $distributor->call('savePricingColumnWidths', ['distributor-final-price' => 90]);
        $this->assertSame(['distributor-final-price' => 115], Livewire::test(PricingWholesaleCalculations::class)->instance()->pricingColumnWidths);
        $this->assertSame(['distributor-final-price' => 90], Livewire::test(PricingDistributorCalculations::class)->instance()->pricingColumnWidths);
        $distributor->call('setEurVatPercent', 21);
        $this->assertEquals(21, Livewire::test(PricingWholesaleCalculations::class)->instance()->eurVatPercent);
        $wholesale->call('selectWholesaleCurrency', 'EUR');
        $this->assertEquals(21, Livewire::test(PricingDistributorCalculations::class)->instance()->eurVatPercent);
    }

    public function test_distributor_review_filter_uses_child_status_not_wholesale_status(): void
    {
        $this->calculate();
        $this->row()->update(['requires_review' => true]);
        $page = Livewire::test(PricingDistributorCalculations::class);
        $page->filterTable('distributor_requires_review', true)->assertCanSeeTableRecords([$this->source()]);
        $page->filterTable('distributor_requires_review', false)->assertCanNotSeeTableRecords([$this->source()]);
        $this->assertFalse($this->source()->requires_review);
    }

    public function test_all_resizable_pricing_workspaces_share_wrapping_headers(): void
    {
        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class, PricingForeignRetailCalculations::class,
            PricingWholesaleCalculations::class, PricingDistributorCalculations::class] as $page) {
            Livewire::test($page)->assertSuccessful()->assertSee('data-pricing-resizable-columns', false)
                ->assertSee('overflow-wrap: anywhere;', false)
                ->assertSee('.fi-ta-header-cell-sort-btn', false);
        }
    }
}
