<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingCostDoubleClickTest extends TestCase
{
    use RefreshDatabase;

    protected PricingProject $project;

    protected PricingCalculationRow $cost;

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
        $supplier = Supplier::create(['erp_partner_code' => 'SUPPLIER', 'name' => 'Teszt beszállító', 'country_code' => 'CN', 'active' => true]);
        $product = Product::create(['season_id' => $season->id, 'item_main_group_id' => $group->id,
            'model_code' => 'TEST-COST', 'name_hu' => 'Teszt modell', 'active' => true]);
        ProductPurchasePrice::create(['product_id' => $product->id, 'supplier_id' => $supplier->supplier_id,
            'currency_id' => $currency->id, 'purchase_price' => 100, 'active' => true]);
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt projekt', 'status' => 'active', 'active' => true]);
        $this->project->products()->attach($product);
        $this->cost = PricingCalculationRow::create(['pricing_project_id' => $this->project->id, 'product_id' => $product->id,
            'supplier_id' => $supplier->supplier_id, 'currency_id' => $currency->id, 'purchase_currency_id' => $currency->id,
            'price_type' => 'cost', 'purchase_price' => 100, 'exchange_rate' => 1,
            'shipping_cost_percent' => 8, 'customs_percent' => 12, 'calculated_price' => 120.96]);
    }

    public function test_cost_workspace_and_generic_resource_both_use_text_cells_and_operation_menus(): void
    {
        $page = Livewire::test(PricingCostCalculations::class)->assertSuccessful()->assertDontSee('textInputTableColumn', false);
        $table = $page->instance()->getTable();
        $this->assertSame([], $table->getRecordActions());
        $operations = $table->getColumn('pricing_operations')->record($this->cost)->render();
        $this->assertStringContainsString('Termékenkénti árkezelés', $operations);
        foreach (['shipping_cost_percent', 'customs_percent'] as $field) {
            $column = $table->getColumn($field)->record($this->cost);
            $this->assertInstanceOf(TextColumn::class, $column);
            $this->assertSame('true', $column->getExtraCellAttributes()['data-cost-editable']);
        }
        $legacy = PricingCalculationRowsTable::configure(Table::make($page->instance()));
        $this->assertInstanceOf(TextColumn::class, $legacy->getColumn('customs_percent'));
        $this->assertNotNull($legacy->getColumn('pricing_operations'));
        $this->assertSame([], $legacy->getRecordActions());
    }

    public function test_row_warning_on_cost_and_generic_tables_preserves_double_click_classes(): void
    {
        $page = Livewire::test(PricingCostCalculations::class);
        $classes = $page->instance()->getTable()->getRecordClasses($this->cost);
        $this->assertContains('pricing-cost-row', $classes);
        $this->assertContains('pricing-cost-details-enabled', $classes);
        $this->assertFalse($classes['pricing-needs-attention']);

        $this->cost->update(['requires_review' => true]);
        $this->assertTrue($page->instance()->getTable()->getRecordClasses($this->cost)['pricing-needs-attention']);
        $generic = PricingCalculationRowsTable::configure(Table::make($page->instance()));
        $this->assertTrue($generic->getRecordClasses($this->cost)['pricing-needs-attention']);
    }

    public function test_details_are_loaded_only_when_requested_and_use_the_existing_breakdown_view(): void
    {
        $before = $this->cost->fresh()->getAttributes();
        $page = Livewire::test(PricingCostCalculations::class)->assertActionNotMounted('costDetails')
            ->call('openCostDetails', $this->cost->id)->assertActionMounted('costDetails');
        $this->assertStringContainsString('pricing-detail-table', $page->instance()->getMountedAction()->getModalContent()->render());
        $this->assertSame($before, $this->cost->fresh()->getAttributes());
    }

    public function test_details_reject_other_projects_and_non_cost_records_including_direct_modal_mounts(): void
    {
        $otherProject = $this->project->replicate();
        $otherProject->name = 'Másik projekt';
        $otherProject->save();
        $other = $this->cost->replicate();
        $other->pricing_project_id = $otherProject->id;
        $other->save();
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->project->id)
            ->call('openCostDetails', $other->id)->assertNotFound();
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->project->id)
            ->call('mountAction', 'costDetails', ['record_id' => $other->id])->assertNotFound();
        $this->cost->update(['price_type' => 'retail']);
        Livewire::test(PricingCostCalculations::class)->call('selectPricingProject', $this->project->id)
            ->call('openCostDetails', $this->cost->id)->assertNotFound();
    }

    public function test_edit_preparation_accepts_comma_and_keeps_scope_counts_without_writing_until_confirmation(): void
    {
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, '12,5')
            ->assertSet('pendingParameterAdjustment.value', 12.5)
            ->assertSet('pendingParameterAdjustment.scope_counts.global', 1);
        $this->assertDatabaseCount('pricing_parameters', 0);
        $this->assertSame('12.0000', $this->cost->fresh()->customs_percent);
        $page->call('cancelPendingParameterAdjustment')->assertSet('pendingParameterAdjustment', null);
        $this->assertDatabaseCount('pricing_parameters', 0);
        $page->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, '0')
            ->set('pendingParameterScope', PricingParameter::SCOPE_GLOBAL)->call('confirmPendingParameterAdjustment')->assertHasNoErrors();
        $this->assertSame('0.0000', $this->cost->fresh()->customs_percent);
        $this->assertSame('0.000000', PricingParameter::sole()->value);
    }

    public function test_invalid_percent_values_never_create_a_pending_change(): void
    {
        $page = Livewire::test(PricingCostCalculations::class);
        foreach (['', 'nope', '-1', '10000', '1e9999'] as $value) {
            $page->call('startInlineCostPercentParameterAdjustment', $this->cost->id, PricingParameter::TYPE_CUSTOMS_PERCENT, $value)
                ->assertSet('pendingParameterAdjustment', null);
        }
        $this->assertDatabaseCount('pricing_parameters', 0);
        $this->assertSame('12.0000', $this->cost->fresh()->customs_percent);
    }

    public function test_text_percent_columns_keep_numeric_sort_search_and_excel_export(): void
    {
        $page = Livewire::test(PricingCostCalculations::class)->sortTable('customs_percent', 'asc')
            ->searchTableColumns(['customs_percent' => '12'])->assertCanSeeTableRecords([$this->cost]);
        $columns = array_map(fn ($field) => $page->instance()->getTable()->getColumn($field), ['shipping_cost_percent', 'customs_percent']);
        $export = new AdminTableExport($page->instance()->getTableQueryForExport(), $columns);
        $this->assertSame([8.0, 12.0], $export->map($this->cost));
    }
}
