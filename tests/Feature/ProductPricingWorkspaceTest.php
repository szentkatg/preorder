<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingProductWorkspace;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ItemMainGroup;
use App\Models\PriceList;
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
use App\Services\Pricing\PricingProjectPublicationService;
use App\Services\Pricing\ProductPricingService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPricingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private PricingProject $project;

    private Product $product;

    private Product $other;

    private array $lists;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'W27', 'name' => 'Tél', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name_hu' => 'Forint', 'active' => true]);
        $eur = Currency::create(['code' => 'EUR', 'name_hu' => 'Euró', 'active' => true]);
        $usd = Currency::create(['code' => 'USD', 'name_hu' => 'Dollár', 'active' => true]);
        foreach ([[$eur, 400], [$usd, 380]] as [$currency, $rate]) {
            ExchangeRate::create(['season_id' => $season->id, 'currency_id' => $currency->id, 'rate_to_huf' => $rate, 'active' => true]);
        }
        $group = ItemMainGroup::create(['code' => 'BAG', 'name_hu' => 'Táska', 'active' => true]);
        $supplier = Supplier::create(['erp_partner_code' => 'SUPP', 'name' => 'Beszállító', 'country_code' => 'CN', 'active' => true]);
        foreach (['TEST' => 'product', 'OTHER' => 'other'] as $code => $property) {
            $brand = Brand::create(['code' => $code, 'name' => $code, 'active' => true]);
            $this->{$property} = Product::create(['season_id' => $season->id, 'model_code' => $code, 'name_hu' => $code,
                'item_main_group_id' => $group->id, 'brand_id' => $brand->id, 'catalog_group_name_hu' => $code, 'active' => true]);
            ProductPurchasePrice::create(['product_id' => $this->{$property}->id, 'supplier_id' => $supplier->supplier_id,
                'currency_id' => $usd->id, 'purchase_price' => 10, 'active' => true]);
        }
        $this->project = PricingProject::create(['season_id' => $season->id, 'name' => 'Teszt', 'status' => 'active', 'active' => true]);
        foreach (['shipping_cost_percent' => 8, 'customs_percent' => 12, 'retail_multiplier' => 4.7,
            'country_multiplier' => 1.1, 'wholesale_margin' => 2.5, 'distributor_discount' => 10, 'outlet_discount' => 30] as $type => $value) {
            PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => $type,
                'scope_type' => 'global', 'currency_id' => $type === 'outlet_discount' ? $huf->id : null, 'value' => $value, 'active' => true]);
        }
        foreach (['rh' => ['retail', $huf, null], 're' => ['retail', $eur, null],
            'wh' => ['wholesale', $huf, 'rh'], 'we' => ['wholesale', $eur, 're'],
            'de' => ['distributor', $eur, 'we'], 'oh' => ['outlet', $huf, 'rh']] as $key => [$type, $currency, $source]) {
            $data = ['code' => strtoupper($key), 'name_hu' => $key, 'type' => $type, 'currency_id' => $currency->id, 'active' => true];
            if ($source) {
                $data[match ($type) {
                    'wholesale' => 'retail_price_list_id', 'distributor' => 'source_wholesale_price_list_id', 'outlet' => 'source_retail_price_list_id'
                }] = $this->lists[$source]->id;
            }
            $this->lists[$key] = PriceList::create($data);
            PricingRoundingRule::create(['pricing_project_id' => $this->project->id, 'name' => $key, 'price_type' => $type,
                'price_list_id' => $this->lists[$key]->id, 'currency_id' => $currency->id,
                'round_to' => $currency->code === 'HUF' ? ($type === 'wholesale' ? 100 : 1000) : ($type === 'retail' ? 1 : .01),
                'mode' => 'nearest', 'adjustment' => $currency->code === 'HUF' ? ($type === 'wholesale' ? 0 : -10) : ($type === 'retail' ? -.05 : 0), 'active' => true]);
        }
        app(CostCalculationService::class)->recalculate($this->project);
        app(HufRetailCalculationService::class)->recalculate($this->project);
    }

    private function settings(): array
    {
        return app(ProductPricingService::class)->open($this->project, $this->product);
    }

    private function approve(): array
    {
        $settings = $this->settings();

        return app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
    }

    public function test_preview_recalculates_all_levels_but_rolls_back_every_change(): void
    {
        $settings = $this->settings();
        $settings['data']['parameters'][array_key_first($settings['data']['parameters'])] = '15,5';
        $result = app(ProductPricingService::class)->preview($this->project, $this->product, $settings['data'], $settings['fingerprint']);
        $this->assertCount(6, $result['prices']);
        $this->assertGreaterThan(4596.48, $result['costs'][0]['price']);
        $this->assertSame($settings['fingerprint'], $this->settings()['fingerprint']);
        $this->assertDatabaseCount('pricing_calculation_rows', 4);
        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseCount('pricing_calculation_errors', 0);
    }

    public function test_one_approval_updates_only_selected_product_and_all_prices_are_current(): void
    {
        $otherBefore = PricingCalculationRow::where('product_id', $this->other->id)->get()->toArray();
        $settings = $this->settings();
        $shipping = array_key_first($settings['data']['parameters']);
        $settings['data']['parameters'][$shipping] = 15;
        $settings['data']['parameters']['list_'.$this->lists['oh']->id] = 35;
        $result = app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
        $this->assertCount(6, $result['prices']);
        $this->assertSame($otherBefore, PricingCalculationRow::where('product_id', $this->other->id)->get()->toArray());
        $this->assertSame(6, PricingCalculationRow::where('product_id', $this->product->id)->where('status', 'approved')->where('requires_review', false)->count());
        $this->assertDatabaseHas('pricing_parameters', ['product_id' => $this->product->id, 'scope_type' => 'product', 'value' => 15]);
        $this->assertDatabaseHas('pricing_parameters', ['product_id' => null, 'scope_type' => 'global', 'value' => 8]);
        foreach (PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', '!=', 'cost')->get() as $row) {
            $this->assertFalse($row->needsPricingAttention());
        }
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_manual_retail_price_is_used_by_wholesale_and_distributor(): void
    {
        $settings = $this->settings();
        $settings['data']['manual'][$this->lists['re']->id.'_0'] = '80,00';
        app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id, 'price_list_id' => $this->lists['re']->id, 'final_price' => 80, 'status' => 'modified_approved']);
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id, 'price_list_id' => $this->lists['we']->id, 'final_price' => 32]);
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id, 'price_list_id' => $this->lists['de']->id, 'final_price' => 28.8]);
    }

    public function test_invalid_outlet_rolls_back_purchase_parameters_prices_and_approvals(): void
    {
        $settings = $this->settings();
        $id = array_key_first($settings['data']['purchase_prices']);
        $settings['data']['purchase_prices'][$id] = 20;
        $settings['data']['parameters']['list_'.$this->lists['oh']->id] = 40;
        $settings['data']['manual'][$this->lists['oh']->id.'_0'] = 99999;
        try {
            app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
            $this->fail('Invalid outlet must reject the whole approval.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('minimum', $exception->getMessage());
        }
        $this->assertSame($settings['fingerprint'], $this->settings()['fingerprint']);
        $this->assertSame('10.0000', ProductPurchasePrice::findOrFail($id)->purchase_price);
    }

    public function test_concurrent_change_rejects_stale_editor(): void
    {
        $settings = $this->settings();
        PricingParameter::where('parameter_type', 'retail_multiplier')->first()->update(['value' => 5]);
        $this->expectException(DomainException::class);
        app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
    }

    public function test_overview_is_read_only_sortable_searchable_and_exports_numeric_multipliers(): void
    {
        $this->approve();
        $page = Livewire::test(PricingProductOverview::class)->assertSuccessful()->assertSee('Termékek összes ára');
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $price = 'overview_'.$this->lists['we']->id.'_price';
        $ratio = 'overview_'.$this->lists['we']->id.'_cost_ratio';
        $cost = $page->instance()->getTableRecords()->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($cost);
        $this->assertGreaterThan(0, $cost->{$ratio});
        $this->assertEqualsWithDelta((float) $cost->{$price} * 400 / (float) $cost->final_price, $cost->{$ratio}, .000001);
        $prefix = 'overview_'.$this->lists['de']->id.'_';
        $retail = PricingCalculationRow::where('product_id', $this->product->id)->where('price_list_id', $this->lists['re']->id)->firstOrFail();
        $wholesale = PricingCalculationRow::where('product_id', $this->product->id)->where('price_list_id', $this->lists['we']->id)->firstOrFail();
        $distributor = PricingCalculationRow::where('product_id', $this->product->id)->where('price_list_id', $this->lists['de']->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $retail->final_price / (float) $distributor->final_price, $cost->{$prefix.'retail_ratio'}, .000001);
        $this->assertEqualsWithDelta((float) $wholesale->final_price / (float) $distributor->final_price, $cost->{$prefix.'wholesale_ratio'}, .000001);
        $this->assertEqualsWithDelta((float) $distributor->final_price * 400 / (float) $cost->final_price, $cost->{$prefix.'cost_ratio'}, .000001);
        $page->sortTable($price)->searchTableColumns([$price => (string) $cost->{$price}])->assertCanSeeTableRecords([$cost]);
        $page->call('savePricingDecimalPlaces', [$ratio => 2]);
        $column = $page->instance()->getTable()->getColumn($ratio);
        $export = new AdminTableExport($page->instance()->getFilteredTableQuery(), [$column]);
        $this->assertIsFloat($export->map($cost)[0]);
    }

    public function test_workspace_modal_previews_then_one_button_commits_all_prices(): void
    {
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(PricingProductWorkspace::class)->mountTableAction('editProductPricing', $cost);
        $this->assertSame('editProductPricing', $page->instance()->getMountedAction()->getName());
        $this->assertNull($page->instance()->pricingEditorError);
        $this->assertCount(6, $page->instance()->pricingEditorPreview['prices']);
        $this->assertDatabaseCount('pricing_calculation_rows', 4);
        $page->callMountedTableAction()->assertHasNoErrors();
        $this->assertSame(6, PricingCalculationRow::where('product_id', $this->product->id)->where('status', 'approved')->count());
    }

    public function test_common_multiselect_filters_apply_brand_catalog_supplier_and_status(): void
    {
        $this->approve();
        $page = Livewire::test(PricingProductOverview::class);
        $costs = PricingCalculationRow::where('price_type', 'cost')->get();
        $selected = $costs->firstWhere('product_id', $this->product->id);
        $other = $costs->firstWhere('product_id', $this->other->id);
        $page->filterTable('common_brand_id', [$this->product->brand_id])->assertCanSeeTableRecords([$selected])->assertCanNotSeeTableRecords([$other]);
        $page->removeTableFilter('common_brand_id')->filterTable('common_catalog_group', ['TEST', 'OTHER'])->assertCanSeeTableRecords($costs);
        $page->filterTable('common_supplier', [$selected->supplier_id])->assertCanSeeTableRecords($costs);
    }

    public function test_read_only_user_cannot_mount_workspace_edit_or_forge_preview(): void
    {
        $user = User::factory()->create();
        foreach (['View:PricingProductWorkspace', 'ViewAny:PricingCalculationRow'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($user);
        Livewire::test(PricingProductWorkspace::class)->call('refreshProductPricingPreview')->assertForbidden();
    }

    public function test_invalid_preview_keeps_editable_fields_and_last_successful_preview(): void
    {
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(PricingProductWorkspace::class)->mountTableAction('editProductPricing', $cost);
        $preview = $page->instance()->pricingEditorPreview;
        $page->set('pricingEditor.manual.'.$this->lists['oh']->id.'_0', '99999');
        $this->assertNotNull($page->instance()->pricingEditorError);
        $this->assertSame($preview, $page->instance()->pricingEditorPreview);
        $page->set('pricingEditor.manual.'.$this->lists['oh']->id.'_0', '');
        $this->assertNull($page->instance()->pricingEditorError);
        $this->assertDatabaseCount('pricing_calculation_rows', 4);
    }

    public function test_overview_status_and_review_filters_use_all_displayed_price_rows(): void
    {
        $this->approve();
        $costs = PricingCalculationRow::where('price_type', 'cost')->get();
        $selected = $costs->firstWhere('product_id', $this->product->id);
        $other = $costs->firstWhere('product_id', $this->other->id);
        Livewire::test(PricingProductOverview::class)->filterTable('common_status', ['approved'])
            ->assertCanSeeTableRecords([$selected])->assertCanNotSeeTableRecords([$other]);
        PricingCalculationRow::where('product_id', $this->product->id)->where('price_list_id', $this->lists['de']->id)->update(['requires_review' => true]);
        Livewire::test(PricingProductOverview::class)->filterTable('requires_review', true)
            ->assertCanSeeTableRecords([$selected])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_closed_project_rejects_product_editor_writes(): void
    {
        $settings = $this->settings();
        $this->project->update(['status' => 'closed']);
        $this->expectException(DomainException::class);
        app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
    }

    public function test_missing_wholesale_parameter_rolls_back_entire_workflow(): void
    {
        $this->approve();
        PricingParameter::where('parameter_type', 'wholesale_margin')->delete();
        $settings = $this->settings();
        $this->expectException(ValidationException::class);
        app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
    }

    public function test_approved_product_prices_pass_the_actual_publication_validator(): void
    {
        $this->approve();
        $settings = app(ProductPricingService::class)->open($this->project, $this->other);
        app(ProductPricingService::class)->approve($this->project, $this->other, $settings['data'], $settings['fingerprint']);
        $snapshot = app(PricingProjectPublicationService::class)->prepare($this->project);
        $this->assertCount(12, $snapshot['prices']);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_overview_excel_contains_real_numeric_prices_and_configured_decimal_formats(): void
    {
        $this->approve();
        $page = Livewire::test(PricingProductOverview::class);
        $name = 'overview_'.$this->lists['de']->id.'_cost_ratio';
        $page->call('savePricingDecimalPlaces', [$name => 2]);
        $columns = [$page->instance()->getTable()->getColumn('product.model_code'), $page->instance()->getTable()->getColumn($name)];
        $export = new AdminTableExport($page->instance()->getFilteredTableQuery()->where('product_id', $this->product->id), $columns,
            $page->instance()->pricingDecimalExportFormats($columns));
        $previousBinder = Cell::getValueBinder();
        $bytes = \Maatwebsite\Excel\Facades\Excel::raw($export, Excel::XLSX);
        $file = tempnam(sys_get_temp_dir(), 'pricing-overview-test-');
        try {
            file_put_contents($file, $bytes);
            Cell::setValueBinder(new DefaultValueBinder);
            $sheet = IOFactory::load($file)->getActiveSheet();
            $this->assertSame('TEST', $sheet->getCell('A2')->getValue());
            $this->assertSame('n', $sheet->getCell('B2')->getDataType());
            $this->assertSame('#,##0.00', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        } finally {
            Cell::setValueBinder($previousBinder);
            unlink($file);
        }
    }

    public function test_new_permissions_migration_marks_only_open_project_outlets_for_review(): void
    {
        $this->approve();
        $outlet = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'outlet')->firstOrFail();
        $closed = $this->project->replicate();
        $closed->name = 'Lezárt';
        $closed->status = 'closed';
        $closed->save();
        $archived = $outlet->replicate();
        $archived->pricing_project_id = $closed->id;
        $archived->save();
        $migration = require database_path('migrations/2026_10_17_090000_add_product_pricing_page_permissions.php');
        $migration->up();
        $this->assertTrue($outlet->fresh()->requires_review);
        $this->assertFalse($archived->fresh()->requires_review);
        $this->assertDatabaseHas('permissions', ['name' => 'View:PricingProductWorkspace', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'View:PricingProductOverview', 'guard_name' => 'web']);
    }

    public function test_common_filters_do_not_break_existing_admin_resource_lists(): void
    {
        $tested = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;
            if (! $index) {
                continue;
            }
            Livewire::test($index->getPage())->assertSuccessful();
            $tested++;
        }
        $this->assertGreaterThan(25, $tested);
    }

    public function test_new_page_permissions_are_independent_and_do_not_grant_editing(): void
    {
        $reader = User::factory()->create();
        foreach (['View:PricingProductOverview', 'ViewAny:PricingCalculationRow'] as $permission) {
            $reader->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($reader);
        Livewire::test(PricingProductOverview::class)->assertSuccessful();
        Livewire::test(PricingProductWorkspace::class)->assertForbidden();
    }
}
