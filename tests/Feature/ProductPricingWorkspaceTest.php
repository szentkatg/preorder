<?php

namespace Tests\Feature;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingForeignRetailCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingProductWorkspace;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Filament\Support\ProductPricingNavigation;
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
use App\Services\Pricing\PricingProjectCalculationService;
use App\Services\Pricing\PricingProjectPublicationService;
use App\Services\Pricing\ProductPricingService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Auth\Access\AuthorizationException;
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

    public function test_project_button_calculates_every_level_without_approving_or_publishing(): void
    {
        PricingCalculationRow::query()->delete();
        $parameters = PricingParameter::all()->toArray();
        $result = app(PricingProjectCalculationService::class)->calculate($this->project);
        $this->assertSame(2, $result['products']);
        $this->assertSame(14, $result['prices']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseCount('pricing_calculation_rows', 14);
        $this->assertSame(['calculated'], PricingCalculationRow::query()->distinct()->pluck('status')->all());
        $this->assertSame($parameters, PricingParameter::all()->toArray());
        $this->assertDatabaseCount('price_list_items', 0);
        $rows = PricingCalculationRow::where('product_id', $this->product->id)->get()->keyBy('price_list_id');
        $this->assertSame($rows[$this->lists['re']->id]->id, $rows[$this->lists['we']->id]->source_retail_row_id);
        $this->assertSame($rows[$this->lists['we']->id]->id, $rows[$this->lists['de']->id]->source_wholesale_row_id);
        $this->assertSame(0, app(PricingProjectCalculationService::class)->calculate($this->project)['failed']);
        $this->assertDatabaseCount('pricing_calculation_rows', 14);
    }

    public function test_project_recalculation_retains_manual_approved_prices_and_propagates_review(): void
    {
        $settings = $this->settings();
        $settings['data']['manual'][$this->lists['re']->id.'_0'] = 80;
        app(ProductPricingService::class)->approve($this->project, $this->product, $settings['data'], $settings['fingerprint']);
        $before = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', '!=', 'cost')->get()->keyBy('id');
        PricingParameter::where('parameter_type', 'shipping_cost_percent')->firstOrFail()->update(['value' => 25]);
        $result = app(PricingProjectCalculationService::class)->calculate($this->project);
        $this->assertSame([], $result['errors']);
        foreach ($before as $old) {
            $new = $old->fresh();
            $this->assertSame($old->status, $new->status);
            $this->assertSame($old->manual_price, $new->manual_price);
            $this->assertSame($old->final_price, $new->final_price);
            $this->assertTrue($new->requires_review, $new->price_type);
        }
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id,
            'price_list_id' => $this->lists['we']->id, 'base_price' => 80, 'final_price' => 32]);
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_failed_model_is_rolled_back_logged_and_does_not_block_other_models(): void
    {
        $before = PricingCalculationRow::where('product_id', $this->product->id)->get()->keyBy('id');
        PricingParameter::create(['pricing_project_id' => $this->project->id, 'parameter_type' => 'wholesale_margin',
            'scope_type' => 'product', 'product_id' => $this->product->id, 'value' => 0, 'active' => true]);
        $result = app(PricingProjectCalculationService::class)->calculate($this->project);
        $this->assertSame(1, $result['products']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString($this->product->model_code, $result['errors'][0]);
        $this->assertSame(2, PricingCalculationRow::where('product_id', $this->product->id)->count());
        foreach ($before as $row) {
            $this->assertSame($row->final_price, $row->fresh()->final_price);
            $this->assertTrue($row->fresh()->requires_review);
        }
        $this->assertSame(7, PricingCalculationRow::where('product_id', $this->other->id)->count());
        $this->assertDatabaseHas('pricing_calculation_errors', ['run_id' => $result['run_id'], 'product_id' => $this->product->id, 'price_type' => 'project']);
        PricingParameter::where('product_id', $this->product->id)->firstOrFail()->update(['value' => 2.5]);
        $this->assertSame(0, app(PricingProjectCalculationService::class)->calculate($this->project)['failed']);
        $this->assertFalse($before->first()->fresh()->requires_review);
    }

    public function test_missing_rounding_is_a_visible_error_instead_of_silently_omitting_an_enabled_price(): void
    {
        PricingRoundingRule::where('price_list_id', $this->lists['de']->id)->delete();
        $result = app(PricingProjectCalculationService::class)->calculate($this->project);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['products']);
        $this->assertDatabaseCount('pricing_calculation_rows', 4);
        $this->assertDatabaseCount('pricing_calculation_errors', 2);
    }

    public function test_ambiguous_foreign_source_requires_selection_and_selected_source_is_used(): void
    {
        $huf = $this->lists['rh']->replicate();
        $huf->code = 'RH2';
        $huf->save();
        $rule = PricingRoundingRule::where('price_list_id', $this->lists['rh']->id)->firstOrFail()->replicate();
        $rule->price_list_id = $huf->id;
        $rule->save();
        $this->assertSame(2, app(PricingProjectCalculationService::class)->calculate($this->project)['failed']);
        $result = app(PricingProjectCalculationService::class)->calculate($this->project, [$this->lists['re']->id => $huf->id]);
        $this->assertSame([], $result['errors']);
        $source = PricingCalculationRow::where('product_id', $this->product->id)->where('price_list_id', $huf->id)->firstOrFail();
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id,
            'price_list_id' => $this->lists['re']->id, 'source_retail_row_id' => $source->id]);
    }

    public function test_navigation_is_available_on_all_pricing_grids_including_cost_and_overview(): void
    {
        $this->approve();
        $pages = [PricingCostCalculations::class => 'cost',
            PricingHufRetailCalculations::class => 'retail',
            PricingForeignRetailCalculations::class => 'retail',
            PricingWholesaleCalculations::class => 'wholesale',
            PricingDistributorCalculations::class => 'wholesale',
            PricingProductOverview::class => 'cost'];
        foreach ($pages as $class => $type) {
            $page = Livewire::test($class);
            if ($class === PricingWholesaleCalculations::class) {
                $page->call('selectWholesaleCurrency', 'EUR');
            }
            $record = $page->instance()->getTable()->getQuery()->where('product_id', $this->product->id)->firstOrFail();
            $column = $page->instance()->getTable()->getColumn('pricing_operations')->record($record);
            $this->assertStringContainsString('Termékenkénti árkezelés', $column->render(), $class);
        }
    }

    public function test_forged_product_project_pair_cannot_open_another_seasons_product(): void
    {
        $season = Season::create(['code' => 'S28', 'name' => 'Másik szezon', 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Másik', 'status' => 'active', 'active' => true]);
        Livewire::withQueryParams(['project' => $project->id, 'product' => $this->product->id])->test(PricingProductWorkspace::class)->assertNotFound();
    }

    public function test_read_only_user_cannot_invoke_project_wide_calculation(): void
    {
        $reader = User::factory()->create();
        foreach (['View:PricingProject', 'ViewAny:PricingProject', 'ViewAny:PricingCalculationRow'] as $permission) {
            $reader->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($reader);
        Livewire::test(ListPricingProjects::class)
            ->assertTableActionHidden('calculateAllPrices', $this->project);
        $this->expectException(AuthorizationException::class);
        app(PricingProjectCalculationService::class)->calculate($this->project);
    }

    public function test_project_recalculation_rejects_invalid_source_pair_before_writes(): void
    {
        $before = PricingCalculationRow::all()->toArray();
        try {
            app(PricingProjectCalculationService::class)->calculate($this->project, [$this->lists['re']->id => $this->lists['we']->id]);
            $this->fail('Invalid source was accepted.');
        } catch (DomainException) {
            $this->assertSame($before, PricingCalculationRow::all()->toArray());
        }
    }

    public function test_project_calculation_action_is_available_on_list_and_detail_and_hidden_when_closed(): void
    {
        $page = Livewire::test(ListPricingProjects::class);
        $page->assertTableActionVisible('calculateAllPrices', $this->project)
            ->callTableAction('calculateAllPrices', $this->project)->assertHasNoErrors();
        $this->assertDatabaseCount('pricing_calculation_rows', 14);
        Livewire::test(ViewPricingProject::class, ['record' => $this->project->id])
            ->assertActionVisible('calculateAllPrices');
        $this->project->update(['status' => 'closed']);
        Livewire::test(ListPricingProjects::class)
            ->assertTableActionHidden('calculateAllPrices', $this->project);
        $this->expectException(DomainException::class);
        app(PricingProjectCalculationService::class)->calculate($this->project);
    }

    public function test_preview_tables_show_live_cost_and_commercial_multipliers(): void
    {
        $settings = $this->settings();
        $settings['data']['manual'][$this->lists['re']->id.'_0'] = 80;
        $result = app(ProductPricingService::class)->preview($this->project, $this->product, $settings['data'], $settings['fingerprint']);
        $prices = collect($result['prices'])->keyBy('list');
        $this->assertEqualsWithDelta(80 * 400 / 4596.48, $prices['RE']['cost_multiplier'], .00001);
        $this->assertNull($prices['RE']['retail_multiplier']);
        $this->assertEqualsWithDelta(2.5, $prices['WE']['retail_multiplier'], .00001);
        $this->assertEqualsWithDelta(32 / 28.8, $prices['DE']['wholesale_multiplier'], .00001);
        $this->assertEqualsWithDelta(80 / 28.8, $prices['DE']['retail_multiplier'], .00001);
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(PricingProductWorkspace::class)->mountTableAction('editProductPricing', $cost);
        $html = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('product-pricing-tables', $html);
        $this->assertStringContainsString('product-pricing-parameters', $html);
        $this->assertStringContainsString('Bekerülési szorzó', $html);
        $this->assertStringContainsString('Nagyker / adott ár', $html);
    }

    public function test_legacy_direct_link_opens_selected_product_and_project_but_menus_use_local_actions(): void
    {
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $items = ProductPricingNavigation::items($cost);
        $this->assertCount(1, $items);
        $this->assertSame('productPricing', $items[0]['action']);
        $this->assertArrayNotHasKey('url', $items[0]);
        $otherProject = $this->project->replicate();
        $otherProject->name = 'Másik projekt';
        $otherProject->save();
        Livewire::test(PricingProductWorkspace::class)->call('selectPricingProject', $otherProject->id);
        $page = Livewire::withQueryParams(['project' => $this->project->id, 'product' => $this->product->id])->test(PricingProductWorkspace::class);
        $this->assertSame($this->project->id, $page->instance()->pricingProjectId);
        $this->assertSame(['table' => true, 'recordKey' => (string) $cost->id], $page->instance()->defaultActionContext);
        $page->call('mountAction', 'editProductPricing', [], ['table' => true, 'recordKey' => (string) $cost->id])->assertHasNoErrors();
        $this->assertSame($this->product->id, $page->instance()->pricingEditorProductId);
    }

    public function test_navigation_and_direct_link_do_not_bypass_edit_permissions(): void
    {
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $reader = User::factory()->create();
        foreach (['View:PricingProductWorkspace', 'ViewAny:PricingCalculationRow', 'View:PricingCalculationRow'] as $permission) {
            $reader->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($reader);
        $this->assertSame([], ProductPricingNavigation::items($cost));
        Livewire::withQueryParams(['project' => $this->project->id, 'product' => $this->product->id])->test(PricingProductWorkspace::class)->assertForbidden();
    }

    public function test_each_pricing_list_opens_the_clicked_product_in_place_and_cancel_keeps_list_state(): void
    {
        $this->approve();
        $pages = [PricingCostCalculations::class, PricingHufRetailCalculations::class,
            PricingForeignRetailCalculations::class, PricingWholesaleCalculations::class,
            PricingDistributorCalculations::class, PricingProductOverview::class,
            ListPricingCalculationRows::class];
        foreach ($pages as $class) {
            $page = Livewire::test($class)->filterTable('common_brand_id', [$this->product->brand_id])->sortTable('final_price', 'desc');
            $row = $page->instance()->getTable()->getQuery()->where('product_id', $this->product->id)->firstOrFail();
            $before = $this->pricingListState($page->instance());
            $page->call('mountAction', 'productPricing', ['record_id' => $row->id])->assertActionMounted('productPricing')->assertHasNoErrors();
            $this->assertSame($this->product->id, $page->instance()->pricingEditorProductId, $class);
            $this->assertSame($this->project->id, $page->instance()->pricingEditorProjectId, $class);
            $this->assertArrayNotHasKey('redirect', $page->effects);
            $page->call('unmountAction')->assertActionNotMounted('productPricing');
            $this->assertSame($before, $this->pricingListState($page->instance()), $class);
            $this->assertSame([], $page->instance()->pricingEditor);
            $this->assertNull($page->instance()->pricingEditorProductId);
            $this->assertArrayNotHasKey('redirect', $page->effects);
        }
    }

    public function test_in_place_save_refreshes_prices_without_resetting_filters_pagination_or_columns(): void
    {
        $this->approve();
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->filterTable('common_brand_id', [$this->product->brand_id])->sortTable('final_price', 'desc');
        $row = $page->instance()->getTable()->getQuery()->where('product_id', $this->product->id)->firstOrFail();
        $page->set('paginators.page', 2);
        $before = $this->pricingListState($page->instance());
        $otherBefore = PricingCalculationRow::where('product_id', $this->other->id)->get()->toArray();
        $page->call('mountAction', 'productPricing', ['record_id' => $row->id])
            ->set('pricingEditor.manual.'.$this->lists['re']->id.'_0', '80')
            ->callMountedAction()->assertHasNoErrors()->assertActionNotMounted('productPricing');
        $this->assertSame($before, $this->pricingListState($page->instance()));
        $this->assertSame($otherBefore, PricingCalculationRow::where('product_id', $this->other->id)->get()->toArray());
        $this->assertArrayNotHasKey('redirect', $page->effects);
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id, 'price_list_id' => $this->lists['re']->id,
            'manual_price' => 80, 'status' => 'modified_approved']);
        $this->assertDatabaseHas('pricing_calculation_rows', ['product_id' => $this->product->id, 'price_list_id' => $this->lists['we']->id, 'final_price' => 32]);
    }

    public function test_general_list_uses_record_project_instead_of_saved_workspace_context(): void
    {
        $otherProject = $this->project->replicate();
        $otherProject->name = 'Másik';
        $otherProject->save();
        Livewire::test(PricingProductWorkspace::class)->call('selectPricingProject', $otherProject->id);
        $row = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(ListPricingCalculationRows::class)
            ->call('mountAction', 'productPricing', ['record_id' => $row->id])->assertActionMounted('productPricing');
        $this->assertSame($this->project->id, $page->instance()->pricingEditorProjectId);
        $this->assertSame($this->product->id, $page->instance()->pricingEditorProductId);
        $page->call('unmountAction');
        $saved = Livewire::test(PricingProductWorkspace::class);
        $this->assertSame($otherProject->id, $saved->instance()->pricingProjectId);
    }

    public function test_overview_adds_new_price_columns_after_in_place_save_without_losing_filters(): void
    {
        $page = Livewire::test(PricingProductOverview::class)->filterTable('common_brand_id', [$this->product->brand_id]);
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $column = 'overview_'.$this->lists['we']->id.'_price';
        $this->assertNull($page->instance()->getTable()->getColumn($column));
        $before = $page->instance()->tableFilters;
        $page->call('mountAction', 'productPricing', ['record_id' => $cost->id])->callMountedAction()->assertHasNoErrors();
        $this->assertNotNull($page->instance()->getTable()->getColumn($column));
        $this->assertSame($before, $page->instance()->tableFilters);
        $this->assertArrayNotHasKey('redirect', $page->effects);
    }

    public function test_general_list_has_lazy_percent_cells_grouped_operations_and_type_specific_details(): void
    {
        $this->approve();
        $class = ListPricingCalculationRows::class;
        $page = Livewire::test($class)->assertDontSee('textInputTableColumn', false);
        $this->assertSame([], $page->instance()->getTable()->getRecordActions());
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $this->assertNull($page->instance()->getTable()->getRecordAction($cost));
        $this->assertNull($page->instance()->getTable()->getRecordUrl($cost));
        foreach (['shipping_cost_percent', 'customs_percent'] as $field) {
            $column = $page->instance()->getTable()->getColumn($field)->record($cost);
            $this->assertInstanceOf(TextColumn::class, $column);
            $this->assertSame('true', $column->getExtraCellAttributes()['data-cost-editable']);
        }
        $this->assertCount(5, $page->instance()->pricingRowOperations($cost));
        foreach (['cost' => 'pricing-detail-table', 'retail' => 'Bekerülési', 'wholesale' => 'pricing-wholesale-details',
            'distributor' => 'pricing-distributor-details', 'outlet' => 'Outlet kalkuláció'] as $type => $expected) {
            $row = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', $type)->firstOrFail();
            $page->call('openCostDetails', $row->id)->assertActionMounted('costDetails');
            $this->assertStringContainsString($expected, $page->instance()->getMountedAction()->getModalContent()->render());
            $page->call('unmountAction');
        }
    }

    public function test_general_inline_percent_waits_for_scope_confirmation_and_can_be_cancelled(): void
    {
        $class = ListPricingCalculationRows::class;
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $before = PricingParameter::all()->toArray();
        $page = Livewire::test($class)->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', '15,5')
            ->assertActionMounted('updateCustomsPercent')->assertSchemaStateSet(['value' => '15.5']);
        $this->assertSame($before, PricingParameter::all()->toArray());
        $this->assertSame('12.0000', $cost->fresh()->customs_percent);
        $page->call('unmountAction');
        $this->assertSame($before, PricingParameter::all()->toArray());
        $page->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', '15,5')
            ->fillForm(['scope_type' => 'product', 'value' => 15.5])->callMountedAction()->assertHasNoErrors();
        $this->assertDatabaseHas('pricing_parameters', ['product_id' => $this->product->id, 'parameter_type' => 'customs_percent', 'value' => 15.5]);
        $this->assertSame('15.5000', $cost->fresh()->customs_percent);
    }

    public function test_general_list_blocks_percent_writes_on_non_cost_closed_or_read_only_records(): void
    {
        $class = ListPricingCalculationRows::class;
        $retail = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'retail')->firstOrFail();
        Livewire::test($class)->call('startInlineCostPercentParameterAdjustment', $retail->id, 'customs_percent', '9')->assertForbidden();
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $reader = User::factory()->create();
        foreach (['ViewAny:PricingCalculationRow', 'View:PricingCalculationRow'] as $permission) {
            $reader->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($reader);
        $page = Livewire::test($class);
        $this->assertSame([['action' => 'costDetails', 'label' => 'Részletek']], $page->instance()->pricingRowOperations($cost));
        $page->call('openCostDetails', $cost->id)->assertActionMounted('costDetails');
        Livewire::test($class)->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', '9')->assertForbidden();
        Livewire::test($class)->call('mountAction', 'productPricing', ['record_id' => $cost->id])->assertActionNotMounted('productPricing');
    }

    public function test_local_product_editor_rejects_cross_project_ids_and_cannot_preview_after_cancel(): void
    {
        $otherProject = $this->project->replicate();
        $otherProject->name = 'Másik';
        $otherProject->save();
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $foreign = $cost->replicate();
        $foreign->pricing_project_id = $otherProject->id;
        $foreign->save();
        $class = PricingCostCalculations::class;
        Livewire::test($class)->call('selectPricingProject', $this->project->id)
            ->call('mountAction', 'productPricing', ['record_id' => $foreign->id])->assertNotFound();
        $page = Livewire::test($class)->call('selectPricingProject', $this->project->id)
            ->call('mountAction', 'productPricing', ['record_id' => $cost->id])->assertActionMounted('productPricing');
        $page->call('unmountAction')->call('refreshProductPricingPreview')->assertForbidden();
    }

    public function test_local_editor_and_generic_inline_edit_cannot_write_after_project_is_closed(): void
    {
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $page = Livewire::test(PricingCostCalculations::class)
            ->call('mountAction', 'productPricing', ['record_id' => $cost->id])->assertActionMounted('productPricing');
        $before = PricingCalculationRow::all()->toArray();
        $this->project->update(['status' => 'closed']);
        $page->call('refreshProductPricingPreview')->assertNotFound();
        $class = ListPricingCalculationRows::class;
        Livewire::test($class)->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', '9')->assertForbidden();
        Livewire::test($class)->call('mountAction', 'productPricing', ['record_id' => $cost->id])->assertActionNotMounted('productPricing');
        $this->assertSame($before, PricingCalculationRow::all()->toArray());
    }

    public function test_generic_inline_edit_rejects_invalid_values_without_writing(): void
    {
        $class = ListPricingCalculationRows::class;
        $cost = PricingCalculationRow::where('product_id', $this->product->id)->where('price_type', 'cost')->firstOrFail();
        $before = PricingParameter::all()->toArray();
        foreach (['', '-1', '1e999', '10000', ['value' => 9]] as $value) {
            Livewire::test($class)->call('startInlineCostPercentParameterAdjustment', $cost->id, 'customs_percent', $value)->assertStatus(422);
        }
        $this->assertSame($before, PricingParameter::all()->toArray());
        $this->assertSame('12.0000', $cost->fresh()->customs_percent);
    }

    private function pricingListState($page): array
    {
        return ['filters' => $page->tableFilters, 'search' => $page->tableSearch, 'sort' => $page->tableSort,
            'pagination' => $page->paginators, 'columns' => $page->tableColumns,
            'selected' => $page->selectedTableRecords, 'per_page' => $page->tableRecordsPerPage];
    }
}
