<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminImportTemplate;
use App\Filament\Pages\PartnerMasterImport;
use App\Filament\Pages\PriceListImport;
use App\Filament\Pages\ProductMasterImport;
use App\Filament\Pages\SupplierPurchasePriceImport;
use App\Models\User;
use App\Services\Imports\SupplierPurchasePriceImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.locale' => 'hu']);
        Cell::setValueBinder(new DefaultValueBinder);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);
    }

    public static function imports(): array
    {
        return [
            'products' => [AdminImportTemplate::PRODUCTS, ProductMasterImport::class, ['products', 'colors', 'skus', 'assortments']],
            'partners' => [AdminImportTemplate::PARTNERS, PartnerMasterImport::class, ['partners', 'partner_addresses', 'partner_users', 'address_brands', 'address_order_sheet_types']],
            'price lists' => [AdminImportTemplate::PRICE_LISTS, PriceListImport::class, ['price_lists', 'price_list_items']],
            'suppliers' => [AdminImportTemplate::SUPPLIERS, SupplierPurchasePriceImport::class, ['suppliers', 'product_purchase_prices']],
        ];
    }

    #[DataProvider('imports')]
    public function test_import_pages_download_their_own_template_without_an_uploaded_file(string $type, string $page, array $sheets): void
    {
        Excel::fake();
        $template = new AdminImportTemplate($type);
        Livewire::test($page)->assertSuccessful()->assertActionVisible('downloadTemplate')->callAction('downloadTemplate')->assertHasNoErrors();
        Excel::assertDownloaded($template->filename(), fn (AdminImportTemplate $download) => $download->type === $type);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('price_lists', 0);
    }

    #[DataProvider('imports')]
    public function test_templates_are_empty_have_current_headers_and_pass_the_actual_import_validator(string $type, string $pageClass, array $sheetNames): void
    {
        $template = new AdminImportTemplate($type);
        $path = tempnam(sys_get_temp_dir(), 'import-template-');
        $book = null;
        try {
            file_put_contents($path, Excel::raw($template, \Maatwebsite\Excel\Excel::XLSX));
            $book = IOFactory::load($path);
            $this->assertSame([...$sheetNames, 'Kitöltési útmutató'], $book->getSheetNames());
            foreach ($sheetNames as $name) {
                $sheet = $book->getSheetByName($name);
                $this->assertSame(1, $sheet->getHighestDataRow(), 'No sample records should be importable: '.$name);
                $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
                $this->assertNotContains('name_en', $headers);
                $this->assertNotContains('catalog_group_name_en', $headers);
                if ($name === 'products') {
                    $this->assertContains('visible_to_partners', $headers);
                }
                if ($name === 'price_lists') {
                    $this->assertNotContains('season_code', $headers);
                    $this->assertNotContains('name_hu', $headers);
                    $this->assertContains('name', $headers);
                }
                $this->assertSame('A2', $sheet->getFreezePane());
                $xf = $sheet->getColumnDimension('A')->getXfIndex();
                $this->assertNotNull($xf);
                $this->assertSame('@', $book->getCellXfByIndex($xf)->getNumberFormat()->getFormatCode());
            }
            $this->assertGreaterThan(5, $book->getSheetByName('Kitöltési útmutató')->getHighestDataRow());
            $page = new $pageClass;
            $page->data = ['file' => new UploadedFile($path, 'minta.xlsx', null, null, true)];
            if ($page instanceof SupplierPurchasePriceImport) {
                $page->validateImport(app(SupplierPurchasePriceImportService::class));
            } else {
                $page->validateImport();
            }
            $this->assertSame([], $page->fatalValidationErrors);
            $this->assertSame([], $page->rowValidationErrors);
            if (property_exists($page, 'sheets')) {
                foreach ($page->sheets as $rows) {
                    $this->assertSame([], $rows);
                }
            }
        } finally {
            $book?->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[DataProvider('imports')]
    public function test_the_import_page_and_its_download_are_not_available_without_the_page_permission(string $type, string $page, array $sheets): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse($page::canAccess());
        Livewire::test($page)->assertForbidden();
    }

    public function test_new_product_and_supplier_fields_are_included_and_numeric_columns_remain_numeric(): void
    {
        $products = (new AdminImportTemplate(AdminImportTemplate::PRODUCTS))->sheets();
        $this->assertContains('material_composition', $products[0]->headings());
        $this->assertContains('promised_delivery_date', $products[0]->headings());
        $this->assertContains('country_code', (new AdminImportTemplate(AdminImportTemplate::SUPPLIERS))->sheets()[0]->headings());
        $this->assertContains('sales_rep_erp_partner_code', (new AdminImportTemplate(AdminImportTemplate::PARTNERS))->sheets()[0]->headings());
        $priceSheet = (new AdminImportTemplate(AdminImportTemplate::PRICE_LISTS))->sheets()[1];
        $this->assertSame('0.0000', $priceSheet->columnFormats()['D:D']);
        $this->assertContains('retail_price_list_code', (new AdminImportTemplate(AdminImportTemplate::PRICE_LISTS))->sheets()[0]->headings());
        $this->assertContains('source_wholesale_price_list_code', (new AdminImportTemplate(AdminImportTemplate::PRICE_LISTS))->sheets()[0]->headings());
    }

    #[DataProvider('imports')]
    public function test_downloaded_templates_can_be_filled_and_imported_with_the_guide_left_in_place(string $type, string $pageClass, array $sheets): void
    {
        $now = ['created_at' => now(), 'updated_at' => now()];
        foreach (['seasons' => ['S27', 'Nyár'], 'brands' => ['BR', 'Márka'], 'order_sheet_types' => ['T', 'Textil'],
            'item_main_groups' => ['B03', 'Oldaltáska'], 'currencies' => ['HUF', 'Forint'], 'languages' => ['HU', 'Magyar']] as $table => [$code, $name]) {
            DB::table($table)->insert(['code' => $code, 'name' => $name, ...$now]);
        }
        DB::table('currencies')->insert(['code' => 'USD', 'name' => 'Dollár', ...$now]);
        if ($type === AdminImportTemplate::PARTNERS) {
            DB::table('price_lists')->insert(['code' => 'WHOLE-TEST', 'name' => 'Nagyker', 'type' => 'wholesale',
                'currency_id' => DB::table('currencies')->where('code', 'HUF')->value('id'), 'active' => 1, ...$now]);
        }
        if ($type === AdminImportTemplate::PRICE_LISTS || $type === AdminImportTemplate::SUPPLIERS) {
            DB::table('products')->insert(['model_code' => 'MODEL-S27', 'name_hu' => 'Modell',
                'item_main_group_id' => DB::table('item_main_groups')->where('code', 'B03')->value('id'),
                'season_id' => DB::table('seasons')->where('code', 'S27')->value('id'), ...$now]);
        }
        $rows = match ($type) {
            AdminImportTemplate::PRODUCTS => [
                'products' => [['model_code' => 'MODEL-S27', 'season_code' => 'S27', 'brand_code' => 'BR', 'order_sheet_type_code' => 'T',
                    'item_main_group_code' => 'B03', 'name_hu' => 'Modell', 'material_composition' => '100% pamut', 'promised_delivery_date' => '2027.03.01', 'active' => 1]],
                'colors' => [['model_code' => 'MODEL-S27', 'color_code' => '01', 'name_hu' => 'NAVY', 'sort_order' => 1, 'active' => 1]],
                'skus' => [['model_code' => 'MODEL-S27', 'color_code' => '01', 'sku_code' => '0000123', 'sku_name' => 'Modell NAVY', 'type' => 'normal', 'active' => 1]],
            ],
            AdminImportTemplate::PARTNERS => [
                'partners' => [['erp_partner_code' => 'P000123', 'name' => 'Mintabolt', 'active' => 1]],
                'partner_addresses' => [['erp_partner_code' => 'P000123', 'addrid' => '000001', 'name' => 'Bolt', 'zip' => '0123', 'language_code' => 'HU', 'currency_code' => 'HUF', 'price_list_code' => 'WHOLE-TEST', 'active' => 1]],
                'address_brands' => [['erp_partner_code' => 'P000123', 'addrid' => '000001', 'brand_code' => 'BR']],
                'address_order_sheet_types' => [['erp_partner_code' => 'P000123', 'addrid' => '000001', 'order_sheet_type_code' => 'T']],
            ],
            AdminImportTemplate::PRICE_LISTS => [
                'price_lists' => [['code' => 'RETAIL-TEST', 'name' => 'Kisker', 'type' => 'retail', 'currency_code' => 'HUF', 'active' => 1]],
                'price_list_items' => [['price_list_code' => 'RETAIL-TEST', 'season_code' => 'S27', 'model_code' => 'MODEL-S27', 'net_price' => 10990]],
            ],
            AdminImportTemplate::SUPPLIERS => [
                'suppliers' => [['erp_partner_code' => 'SUP001', 'name' => 'Beszállító', 'short_name' => 'Rövid név', 'addrid' => '000005', 'country_code' => 'CN', 'active' => 1]],
                'product_purchase_prices' => [['model_code' => 'MODEL-S27', 'supplier_code' => 'SUP001', 'currency_code' => 'USD', 'purchase_price' => 8.8, 'active' => 1]],
            ],
        };
        $path = tempnam(sys_get_temp_dir(), 'filled-import-');
        $book = null;
        try {
            file_put_contents($path, Excel::raw(new AdminImportTemplate($type), \Maatwebsite\Excel\Excel::XLSX));
            $book = IOFactory::load($path);
            foreach ($rows as $sheetName => $items) {
                $sheet = $book->getSheetByName($sheetName);
                $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
                foreach ($items as $rowIndex => $row) {
                    foreach ($headers as $index => $header) {
                        $value = $row[$header] ?? null;
                        $cell = $sheet->getCell([$index + 1, $rowIndex + 2]);
                        is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
                    }
                }
            }
            IOFactory::createWriter($book, 'Xlsx')->save($path);
            $page = new $pageClass;
            $page->data = ['file' => new UploadedFile($path, 'kitoltott-minta.xlsx', null, null, true)];
            if ($page instanceof SupplierPurchasePriceImport) {
                $page->import(app(SupplierPurchasePriceImportService::class));
            } else {
                $page->import();
            }
            $this->assertSame([], $page->fatalValidationErrors);
            $this->assertSame([], $page->rowValidationErrors);
            $this->assertSame('Sikeres', $page->statistics['Import státusz'] ?? null);
            match ($type) {
                AdminImportTemplate::PRODUCTS => $this->assertDatabaseHas('products', ['model_code' => 'MODEL-S27', 'material_composition' => '100% pamut', 'promised_delivery_date' => '2027-03-01']),
                AdminImportTemplate::PARTNERS => $this->assertDatabaseHas('partner_addresses', ['addrid' => '000001', 'zip' => '0123']),
                AdminImportTemplate::PRICE_LISTS => $this->assertDatabaseHas('price_list_items', ['net_price' => 10990]),
                AdminImportTemplate::SUPPLIERS => $this->assertDatabaseHas('product_purchase_prices', ['purchase_price' => 8.8]),
            };
            if ($type === AdminImportTemplate::PRODUCTS) {
                $this->assertDatabaseHas('skus', ['sku_code' => '0000123']);
            }
            if ($type === AdminImportTemplate::SUPPLIERS) {
                $this->assertDatabaseHas('suppliers', ['addrid' => '000005', 'country_code' => 'CN']);
            }
        } finally {
            $book?->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
