<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ColorsRelationManager;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminVisibleTableExportTest extends TestCase
{
    use RefreshDatabase;

    private array $products;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
        $season = Season::create(['code' => 'S27', 'name' => 'S27', 'active' => true]);
        $group = ItemMainGroup::create(['code' => 'B03', 'name' => 'Táska', 'active' => true]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools', 'active' => true]);
        $type = OrderSheetType::create(['code' => 'T', 'name' => 'Textil', 'active' => true]);
        $huf = Currency::create(['code' => 'HUF', 'name' => 'Forint', 'active' => true]);
        $this->list = PriceList::create(['code' => 'RH', 'name' => 'Kisker', 'type' => 'retail', 'currency_id' => $huf->id, 'active' => true]);
        $project = PricingProject::create(['season_id' => $season->id, 'name' => 'Export', 'status' => 'active', 'active' => true]);
        foreach (['KEEP1', 'KEEP2', 'SKIP'] as $index => $code) {
            $product = Product::create(['model_code' => $code, 'name_hu' => $code, 'season_id' => $season->id,
                'item_main_group_id' => $group->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id, 'active' => true]);
            $this->products[] = $product;
            $project->products()->attach($product->id);
            $common = ['pricing_project_id' => $project->id, 'product_id' => $product->id, 'currency_id' => $huf->id,
                'purchase_currency_id' => $huf->id, 'purchase_price' => 8.8 + $index];
            PricingCalculationRow::create($common + ['price_type' => 'cost', 'calculated_price' => 123.4567]);
            PricingCalculationRow::create($common + ['price_type' => 'retail', 'price_list_id' => $this->list->id,
                'base_price' => 123.4567, 'multiplier' => 4.7, 'calculated_price' => 579.3456, 'manual_price' => 496.1299 + $index]);
        }
    }

    private function selectColumns($page, array $names, bool $reorder = false): void
    {
        $state = $page->instance()->tableColumns;
        foreach ($state as &$column) {
            $column['isToggled'] = in_array($column['name'], $names, true);
        }
        unset($column);
        if ($reorder) {
            $keyed = array_column($state, null, 'name');
            $state = [...array_map(fn ($name) => $keyed[$name], $names),
                ...array_values(array_diff_key($keyed, array_flip($names)))];
        }
        $page->call('applyTableColumnManager', $state, $reorder)->assertSuccessful();
    }

    public function test_pricing_download_contains_only_visible_columns_in_user_order_with_matching_formats_and_filtered_sorted_rows(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class)
            ->callAction('decimalPlaces', ['final_price' => 0, 'purchase_price' => 4, 'multiplier' => 2])
            ->searchTableColumns(['product.model_code' => 'KEEP'])->sortTable('product.model_code', 'desc');
        $this->selectColumns($page, ['final_price', 'product.model_code', 'purchase_price'], reorder: true);
        $page->callTableAction('exportExcel')->assertFileDownloaded()->assertHasNoErrors();
        $path = tempnam(sys_get_temp_dir(), 'visible-export-');
        $previousBinder = Cell::getValueBinder();
        try {
            file_put_contents($path, base64_decode($page->effects['download']['content'], true));
            Cell::setValueBinder(new DefaultValueBinder);
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('C', $sheet->getHighestColumn());
            $this->assertSame(3, $sheet->getHighestRow());
            $this->assertSame(['Végleges kisker HUF', 'Modell kód', 'Eredeti besz. ár'], $sheet->rangeToArray('A1:C1')[0]);
            $this->assertSame('KEEP2', $sheet->getCell('B2')->getValue());
            $this->assertSame('KEEP1', $sheet->getCell('B3')->getValue());
            $this->assertSame('n', $sheet->getCell('A2')->getDataType());
            $this->assertEqualsWithDelta(497.1299, $sheet->getCell('A2')->getValue(), .000001);
            $this->assertSame('#,##0', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
            $this->assertSame('#,##0.0000', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
        } finally {
            Cell::setValueBinder($previousBinder);
            unlink($path);
        }
    }

    public function test_export_uses_new_column_selection_after_page_reentry_and_another_change(): void
    {
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->selectColumns($page, ['product.model_code']);
        session()->flush();
        $page = Livewire::test(PricingHufRetailCalculations::class);
        $this->assertSame(['product.model_code'], array_keys($page->instance()->getTable()->getVisibleColumns()));
        $this->selectColumns($page, ['purchase_price', 'final_price'], reorder: true);
        Excel::fake();
        $page->callTableAction('exportExcel')->assertHasNoErrors();
        Excel::assertDownloaded('pricing_calculation_rows-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            $this->assertSame(['Eredeti besz. ár', 'Végleges kisker HUF'], $export->headings());
            $this->assertCount(2, $export->map($export->query()->first()));

            return true;
        });
    }

    public function test_overview_export_includes_only_selected_dynamic_columns_and_excludes_operations(): void
    {
        $calculated = 'overview_'.$this->list->id.'_calculated_price';
        $final = 'overview_'.$this->list->id.'_price';
        $page = Livewire::test(PricingProductOverview::class);
        $this->selectColumns($page, [$calculated, $final, 'pricing_operations'], reorder: true);
        Excel::fake();
        $page->callTableAction('exportExcel')->assertHasNoErrors();
        Excel::assertDownloaded('pricing_calculation_rows-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            $this->assertSame(['RH – Kalkulált kisker HUF', 'RH – Végleges HUF'], $export->headings());
            $this->assertSame([579.3456, 496.1299], $export->map($export->query()->orderBy('product_id')->first()));

            return true;
        });
    }

    public function test_no_file_is_downloaded_when_every_data_column_is_hidden(): void
    {
        foreach ([[], ['pricing_operations']] as $columns) {
            $page = Livewire::test(PricingHufRetailCalculations::class);
            $this->selectColumns($page, $columns);
            $page->callTableAction('exportExcel')->assertNoFileDownloaded()->assertNotified('Nincs exportálható oszlop');
        }
    }

    public function test_product_list_export_respects_toggles_and_conditional_visibility(): void
    {
        $page = Livewire::test(ListProducts::class);
        $this->selectColumns($page, ['model_code', 'name_hu']);
        // Conditional visibility must be respected in addition to user toggles.
        $page->instance()->getTable()->getColumn('name_hu')->hidden();
        $action = $page->instance()->getTable()->getAction('exportExcel');
        Excel::fake();
        $action->call();
        Excel::assertDownloaded('products-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            $this->assertSame(['Modell'], $export->headings());

            return true;
        });
    }

    public function test_related_colors_export_also_omits_disabled_columns(): void
    {
        Color::create(['product_id' => $this->products[0]->id, 'code' => 'NV', 'name_hu' => 'NAVY', 'sort_order' => 1, 'active' => true]);
        for ($index = 2; $index <= 12; $index++) {
            Color::create(['product_id' => $this->products[0]->id, 'code' => sprintf('%02d', $index), 'name_hu' => 'Color '.$index, 'sort_order' => $index, 'active' => true]);
        }
        Color::create(['product_id' => $this->products[1]->id, 'code' => 'BK', 'name_hu' => 'Outside', 'active' => true]);
        $page = Livewire::test(ColorsRelationManager::class, ['ownerRecord' => $this->products[0], 'pageClass' => EditProduct::class])
            ->sortTable('sort_order');
        $this->assertCount(10, $page->instance()->getTableRecords());
        $this->selectColumns($page, ['code', 'name_hu']);
        Excel::fake();
        $page->callTableAction('exportExcel')->assertHasNoErrors();
        Excel::assertDownloaded('colors-'.now()->format('Ymd-His').'.xlsx', function (AdminTableExport $export): bool {
            $this->assertSame(['Kód', 'Név'], $export->headings());
            $this->assertSame(12, $export->query()->count());
            $this->assertSame(['NV', 'NAVY'], $export->map($export->query()->first()));

            return true;
        });
    }
}
