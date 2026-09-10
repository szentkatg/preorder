<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ProductMasterImport;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class ProductMasterImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_rows_are_identified_by_headers_regardless_of_column_order(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray([
            ['name_hu', 'promised_delivery_date', 'model_code'],
            ['Teszt termék', '2027-01-15', 'TEST001'],
        ]);

        $rows = $this->importPage()->rowsFromSheet($sheet);

        $this->assertSame('TEST001', $rows[0]['model_code']);
        $this->assertSame('Teszt termék', $rows[0]['name_hu']);
        $this->assertSame('2027-01-15', $rows[0]['promised_delivery_date']);
    }

    public function test_product_import_saves_and_validates_promised_delivery_date(): void
    {
        $this->createProductReferences();

        $row = [
            'active' => 1,
            'name_hu' => 'Teszt termék',
            'item_main_group_code' => 'TST',
            'order_sheet_type_code' => 'STD',
            'brand_code' => 'BR',
            'season_code' => 'S01',
            'model_code' => 'TEST001',
            'size_range_code' => null,
            'promised_delivery_date' => '2027. 01. 15.',
        ];

        $page = $this->importPage();

        $this->assertSame([], $page->validateProductRows([$row]));

        $page->importProductRows([$row]);

        $product = Product::query()->where('model_code', 'TEST001')->firstOrFail();

        $this->assertSame('2027-01-15', $product->promised_delivery_date);

        $rowWithoutDate = $row;
        unset($rowWithoutDate['promised_delivery_date']);
        $rowWithoutDate['name_hu'] = 'Frissített teszt termék';

        $page->importProductRows([$rowWithoutDate]);

        $this->assertSame(
            '2027-01-15',
            $product->refresh()->promised_delivery_date,
        );

        $invalidRow = $row;
        $invalidRow['promised_delivery_date'] = '2027-02-31';

        $this->assertNotEmpty($this->importPage()->validateProductRows([$invalidRow]));
    }

    private function importPage(): ProductMasterImport
    {
        return new class extends ProductMasterImport
        {
            public function rowsFromSheet(Worksheet $sheet): array
            {
                return $this->sheetToRows($sheet);
            }

            public function validateProductRows(array $rows): array
            {
                $this->resetImportState();
                $this->sheets = [
                    'products' => $rows,
                    'colors' => [],
                    'skus' => [],
                    'assortments' => [],
                ];
                $this->validateRows();

                return $this->rowValidationErrors;
            }

            public function importProductRows(array $rows): void
            {
                $this->importProducts($rows);
            }
        };
    }

    private function createProductReferences(): void
    {
        $timestamps = [
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('seasons')->insert([
            'name' => 'Teszt szezon',
            'code' => 'S01',
            ...$timestamps,
        ]);
        DB::table('brands')->insert([
            'name' => 'Teszt márka',
            'code' => 'BR',
            ...$timestamps,
        ]);
        DB::table('order_sheet_types')->insert([
            'name_hu' => 'Teszt rendelőlap',
            'code' => 'STD',
            ...$timestamps,
        ]);
        DB::table('item_main_groups')->insert([
            'name_hu' => 'Teszt főcsoport',
            'code' => 'TST',
            ...$timestamps,
        ]);
    }
}
