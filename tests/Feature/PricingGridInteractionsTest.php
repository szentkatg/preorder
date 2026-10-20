<?php

namespace Tests\Feature;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingProductWorkspace;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use App\Services\Pricing\HufRetailCalculationService;
use App\Services\Pricing\RetailCalculationDependencyService;
use App\Services\Pricing\RetailPriceReviewService;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingGridInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private PricingCalculationRow $cost;

    private PricingCalculationRow $retail;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Teszt', 'active' => true]);
        $currency = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'TEST-COST', 'name_hu' => 'Teszt modell', 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt projekt', 'status' => 'active', 'active' => true]);
        $this->project->products()->attach($product);
        $this->cost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id,
            'currency_id' => $currency->id, 'price_type' => 'cost', 'calculated_price' => 120.96, 'calculation_snapshot' => ['test_source' => true]]);
        $list = PriceList::create(['code' => 'RETAIL_HUF', 'name_hu' => 'Kisker', 'type' => 'retail',
            'currency_id' => $this->cost->currency_id, 'active' => true]);
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'retail_multiplier',
            'scope_type' => 'global', 'value' => 4.7, 'active' => true]);
        PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => 'HUF',
            'price_type' => 'retail', 'round_to' => 1, 'mode' => 'nearest', 'adjustment' => 0, 'active' => true]);
        app(HufRetailCalculationService::class)->recalculate($this->project, $list);
        $this->retail = PricingCalculationRow::where('price_type', 'retail')->firstOrFail();
    }

    public function test_manual_price_edit_and_details_cancel_preserve_list_state(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->filterTable('common_item_main_group_id', [(string) $this->retail->product->item_main_group_id])
            ->searchTable('TEST')->searchTableColumns(['product.model_code' => 'TEST'])
            ->sortTable('final_price', 'desc');
        $state = fn () => [$page->instance()->tableFilters, $page->instance()->tableSearch,
            $page->instance()->tableColumnSearches, $page->instance()->tableSort];
        $before = $state();
        $page->call('setPricingManualPrice', $this->retail->id, '500')->assertHasNoErrors();
        $this->assertSame($before, $state());
        $this->assertEquals(500, $this->retail->fresh()->manual_price);
        $page->call('openPricingDetails', $this->retail->id)->assertActionMounted('pricingDetails')->call('unmountAction');
        $this->assertSame($before, $state());
        $page->mountAction('pricingAccept', ['record_id' => $this->retail->id])->callMountedAction()->assertHasNoErrors();
        $this->assertSame($before, $state());
        $this->assertNull($this->retail->fresh()->manual_price);
    }

    public function test_compact_column_manager_is_shared_by_every_pricing_list(): void
    {
        foreach ([PricingCostCalculations::class, PricingHufRetailCalculations::class,
            PricingForeignRetailCalculations::class, PricingWholesaleCalculations::class,
            PricingDistributorCalculations::class, PricingProductOverview::class,
            PricingProductWorkspace::class, ListPricingCalculationRows::class] as $pageClass) {
            $page = Livewire::test($pageClass)
                ->assertSuccessful()->assertSee('data-compact-column-manager="true"', false);
            $table = $page->instance()->getTable();
            $this->assertSame(1, $table->getColumnManagerColumns(), $pageClass);
            $this->assertSame(Width::Small, $table->getColumnManagerWidth(), $pageClass);
            $this->assertTrue($table->hasReorderableColumns(), $pageClass);
            $this->assertStringContainsString('if (! error) close()', $table->getColumnManagerApplyAction()->getAlpineClickHandler());
        }
    }

    public function test_compact_chooser_preserves_saved_order_visibility_widths_and_decimals(): void
    {
        foreach ([PricingHufRetailCalculations::class, PricingForeignRetailCalculations::class] as $pageClass) {
            $page = Livewire::test($pageClass);
            $page->call('savePricingColumnWidths', ['final-price' => 112])
                ->callAction('decimalPlaces', ['final_price' => 2]);
            $columns = array_reverse($page->instance()->tableColumns);
            foreach ($columns as &$column) {
                if ($column['name'] === 'purchase_price') {
                    $column['isToggled'] = false;
                }
            }
            unset($column);
            $page->call('applyTableColumnManager', $columns, true)->assertHasNoErrors();
            $saved = $page->instance()->tableColumns;
            session()->flush();

            $fresh = Livewire::test($pageClass)->assertSuccessful();
            $this->assertSame($saved, $fresh->instance()->tableColumns);
            $this->assertTrue($fresh->instance()->isTableColumnToggledHidden('purchase_price'));
            $this->assertSame(['final-price' => 112], $fresh->instance()->pricingColumnWidths);
            $this->assertSame(['final_price' => 2], $fresh->instance()->pricingDecimalPlaces);
        }
    }

    public function test_editors_and_modals_are_lazy_and_huf_menu_has_five_items(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->assertSuccessful()->assertDontSee('textInputTableColumn', false);
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $column = $page->instance()->getTable()->getColumn('manual_price')->record($this->retail);
        $this->assertSame('true', $column->getExtraCellAttributes()['data-pricing-editable']);
        $this->assertCount(5, $page->instance()->pricingRowOperations($this->retail));
        $this->assertSame('Termékenkénti árkezelés', $page->instance()->pricingRowOperations($this->retail)[4]['label']);
        $page->call('openPricingDetails', $this->retail->id)->assertActionMounted('pricingDetails');
        $this->assertStringContainsString('Bekerülési', $page->instance()->getMountedAction()->getModalContent()->render());
    }

    public function test_row_warning_tracks_approval_and_review_without_changing_prices(): void
    {
        $originalPrice = $this->retail->final_price;
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertTrue($page->instance()->getTable()->getRecordClasses($this->retail)['pricing-needs-attention']);

        $page->callAction('pricingApprove', ['approval_status' => 'approved'], ['record_id' => $this->retail->id])->assertHasNoErrors();
        $this->assertFalse($page->instance()->getTable()->getRecordClasses($this->retail->fresh())['pricing-needs-attention']);

        $this->retail->update(['requires_review' => true]);
        $this->assertTrue($page->instance()->getTable()->getRecordClasses($this->retail->fresh())['pricing-needs-attention']);
        $this->assertSame($originalPrice, $this->retail->fresh()->final_price);
    }

    public function test_manual_comma_price_approval_and_explicit_calculated_reset_work(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->call('setPricingManualPrice', $this->retail->id, '500,25')->assertHasNoErrors();
        $this->assertSame('500.2500', $this->retail->fresh()->final_price);
        $page->callAction('pricingApprove', ['approval_status' => 'approved'], ['record_id' => $this->retail->id])->assertHasNoErrors();
        $this->assertSame('modified_approved', $this->retail->fresh()->status);
        $page->callAction('pricingAccept', [], ['record_id' => $this->retail->id])->assertHasNoErrors();
        $this->assertNull($this->retail->fresh()->manual_price);
        $this->assertSame('approved', $this->retail->fresh()->status);
    }

    public function test_empty_manual_cell_clears_override_without_approving(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)->call('setPricingManualPrice', $this->retail->id, '500');
        $page->call('setPricingManualPrice', $this->retail->id, '')->assertHasNoErrors();
        $this->assertNull($this->retail->fresh()->manual_price);
        $this->assertSame('calculated', $this->retail->fresh()->status);
    }

    public function test_bulk_reset_only_changes_selected_rows_and_details_are_available(): void
    {
        app(RetailPriceReviewService::class)->setManualPrice($this->retail, 500);
        $other = $this->retail->replicate();
        $other->color_id = Color::create(['product_id' => $this->retail->product_id, 'name_hu' => 'Másik', 'code' => 'OTHER', 'active' => true])->id;
        $other->manual_price = 500;
        $other->save();
        Livewire::test(PricingHufRetailCalculations::class)
            ->callTableBulkAction('pricingAcceptBulk', [$this->retail])->assertHasNoErrors();
        $this->assertNull($this->retail->fresh()->manual_price);
        $this->assertSame('500.0000', $other->fresh()->manual_price);
        $page = Livewire::test(PricingHufRetailCalculations::class)->mountTableBulkAction('pricingDetailsBulk', [$this->retail]);
        $this->assertStringContainsString('TEST-COST', $page->instance()->getMountedAction()->getModalContent()->render());
    }

    public function test_forged_cost_and_other_project_records_cannot_be_opened_or_edited(): void
    {
        Livewire::test(PricingHufRetailCalculations::class)->call('openPricingDetails', $this->cost->id)->assertNotFound();
        Livewire::test(PricingHufRetailCalculations::class)->call('setPricingManualPrice', $this->cost->id, '50')->assertNotFound();
        Livewire::test(PricingHufRetailCalculations::class)->mountAction('pricingApprove', ['record_id' => $this->cost->id])->assertNotFound();
        $other = $this->project->replicate();
        $other->save();
        $row = $this->retail->replicate();
        $row->pricing_project_id = $other->id;
        $row->save();
        Livewire::test(PricingHufRetailCalculations::class)->call('selectPricingProject', $this->project->id)
            ->mountAction('pricingDetails', ['record_id' => $row->id])->assertNotFound();
    }

    public function test_read_only_cells_are_not_highlighted_and_cannot_be_forged(): void
    {
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow',
        ]));
        $this->actingAs($reader);
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertSame([['action' => 'pricingDetails', 'label' => 'Részletek']], $page->instance()->pricingRowOperations($this->retail));
        $this->assertSame('false', $page->instance()->getTable()->getColumn('manual_price')->record($this->retail)->getExtraCellAttributes()['data-pricing-editable']);
        $page->call('setPricingManualPrice', $this->retail->id, 500)->assertForbidden();
        Livewire::test(PricingHufRetailCalculations::class)->mountAction('pricingAccept', ['record_id' => $this->retail->id])->assertForbidden();
    }

    public function test_invalid_manual_inputs_do_not_write_and_missing_record_view_blocks_direct_modal_mount(): void
    {
        foreach (['-1', '0', 'NaN', '1e999', '100000000000', '0.00001', ['forged']] as $value) {
            Livewire::test(PricingHufRetailCalculations::class)->call('setPricingManualPrice', $this->retail->id, $value)->assertStatus(422);
            $this->assertNull($this->retail->fresh()->manual_price);
        }
        $reader = User::factory()->create();
        $reader->givePermissionTo(array_map(fn ($name) => Permission::findOrCreate($name, 'web'), [
            'View:PricingHufRetailCalculations', 'ViewAny:PricingCalculationRow',
        ]));
        $this->actingAs($reader);
        Livewire::test(PricingHufRetailCalculations::class)->mountAction('pricingDetails', ['record_id' => $this->retail->id])->assertForbidden();
    }

    public function test_bulk_reset_skips_stale_prices_and_reports_the_reason(): void
    {
        app(RetailPriceReviewService::class)->setManualPrice($this->retail, 500);
        PricingParameter::where('parameter_type', 'retail_multiplier')->update(['value' => 5]);
        $page = Livewire::test(PricingHufRetailCalculations::class)->callTableBulkAction('pricingAcceptBulk', [$this->retail]);
        $this->assertSame('500.0000', $this->retail->fresh()->manual_price);
        $notice = collect(session('filament.claimed_notifications', []))->last();
        $this->assertStringContainsString('Kihagyott: 1 sor.', $notice['body']);
        $this->assertStringContainsString('újraszámítás', $notice['body']);
    }

    public function test_bulk_reset_skips_closed_rows_and_is_atomic_on_unexpected_failure(): void
    {
        app(RetailPriceReviewService::class)->setManualPrice($this->retail, 500);
        $closed = $this->project->replicate();
        $closed->status = 'closed';
        $closed->save();
        $row = $this->retail->replicate();
        $row->pricing_project_id = $closed->id;
        $row->manual_price = 500;
        $row->save();
        $result = app(RetailPriceReviewService::class)->acceptCalculatedPrices(collect([$this->retail, $row]));
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('500.0000', $row->fresh()->manual_price);
        app(RetailPriceReviewService::class)->setManualPrice($this->retail, 500);
        $open = $this->retail->replicate();
        $open->manual_price = 500;
        $open->color_id = Color::create(['product_id' => $this->retail->product_id, 'name_hu' => 'Másik', 'code' => 'OTHER', 'active' => true])->id;
        $open->save();
        $this->mock(RetailCalculationDependencyService::class, function ($mock) use ($open): void {
            $mock->makePartial();
            $mock->shouldReceive('assertCalculationCurrent')->once()->withArgs(fn ($row) => $row->id === $this->retail->id);
            $mock->shouldReceive('assertCalculationCurrent')->once()->withArgs(fn ($row) => $row->id === $open->id)
                ->andThrow(new \RuntimeException('Unexpected test failure'));
        });
        try {
            app(RetailPriceReviewService::class)->acceptCalculatedPrices(collect([$this->retail, $open]));
            $this->fail('An unexpected error must be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected test failure', $exception->getMessage());
            $this->assertSame('500.0000', $this->retail->fresh()->manual_price);
        }
    }
}
