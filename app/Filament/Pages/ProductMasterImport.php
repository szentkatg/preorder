<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Color;
use App\Models\ItemAssortment;
use App\Models\ItemMainGroup;
use App\Models\OrderSheetType;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\SizeRange;
use App\Models\Sku;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Models\ColorImage;


class ProductMasterImport extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|\UnitEnum|null $navigationGroup = 'Import';

    protected static ?string $navigationLabel = 'Terméktörzs import';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.product-master-import';

    public ?array $data = [];

    public array $sheets = [];

    public array $fatalValidationErrors = [];

    public array $rowValidationErrors = [];

    public array $invalidModelCodes = [];

    public array $validationErrors = [];

    public array $statistics = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\FileUpload::make('file')
                    ->label('Excel fájl')
                    ->storeFiles(false)
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->required(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validálás')
                ->action('validateImport'),

            Action::make('import')
                ->label('Importálás')
                ->color('success')
                ->requiresConfirmation()
                ->action('import'),
        ];
    }

    public function validateImport(): void
    {
        $this->resetImportState();

        try {
            $spreadsheet = $this->loadSpreadsheetFromUpload();

            if (! $spreadsheet) {
                $this->syncValidationErrors();

                return;
            }

            $this->validateRequiredSheets($spreadsheet);

            if (! count($this->fatalValidationErrors)) {
                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('products'),
                    [
                        'model_code',
                        'season_code',
                        'brand_code',
                        'order_sheet_type_code',
                        'item_main_group_code',
                        'size_range_code',
                        'name_hu',
                        'active',
                    ],
                    'products'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('colors'),
                    [
                        'model_code',
                        'color_code',
                        'name_hu',
                        'sort_order',
                        'image_url',
                        'active',
                    ],
                    'colors'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('skus'),
                    [
                        'model_code',
                        'color_code',
                        'size_code',
                        'sku_code',
                        'sku_name',
                        'type',
                        'active',
                    ],
                    'skus'
                );

                if ($spreadsheet->getSheetByName('assortments')) {
                    $this->validateRequiredColumns(
                        $spreadsheet->getSheetByName('assortments'),
                        [
                            'model_code',
                            'color_code',
                            'assortment_sku_code',
                            'component_sku_code',
                            'quantity',
                        ],
                        'assortments'
                    );
                }
            }

            if (! count($this->fatalValidationErrors)) {
                $this->loadSheets($spreadsheet);
                $this->validateRows();
            }

            $this->statistics = [
                'Validálás státusz' => count($this->fatalValidationErrors) ? 'Sikertelen' : 'Kész',
                'Fatális hibák' => count($this->fatalValidationErrors),
                'Sorhibák' => count($this->rowValidationErrors),
                'Hibás modellek' => count($this->invalidModelCodes),
                'Product sorok' => count($this->sheets['products'] ?? []),
                'Color sorok' => count($this->sheets['colors'] ?? []),
                'SKU sorok' => count($this->sheets['skus'] ?? []),
                'Assortment sorok' => count($this->sheets['assortments'] ?? []),
            ];
        } catch (\Throwable $e) {
            $this->addFatalError($e->getMessage());

            $this->statistics = [
                'Hiba fájl' => $e->getFile(),
                'Hiba sor' => $e->getLine(),
            ];
        }

        $this->syncValidationErrors();
    }

    public function import(): void
    {
        $this->validateImport();

        if (count($this->fatalValidationErrors)) {
            return;
        }

        DB::transaction(function () {
            $this->importProducts($this->sheets['products'] ?? []);
            $this->importColors($this->sheets['colors'] ?? []);
            $this->importSkus($this->sheets['skus'] ?? []);
            $this->importAssortments($this->sheets['assortments'] ?? []);
        });

        $this->statistics['Import státusz'] = 'Sikeres';
    }

    protected function resetImportState(): void
    {
        $this->sheets = [];
        $this->fatalValidationErrors = [];
        $this->rowValidationErrors = [];
        $this->invalidModelCodes = [];
        $this->validationErrors = [];
        $this->statistics = [];
    }

    protected function loadSpreadsheetFromUpload(): ?Spreadsheet
    {
        $file = $this->data['file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        if (! $file) {
            $this->addFatalError('Nincs kiválasztott fájl.');

            return null;
        }

        if (! method_exists($file, 'getRealPath')) {
            $this->addFatalError('A feltöltött fájl nem olvasható. Töltsd fel újra az Excel fájlt.');

            return null;
        }

        return IOFactory::load($file->getRealPath());
    }

    protected function validateRequiredSheets(Spreadsheet $spreadsheet): void
    {
        $sheetNames = $spreadsheet->getSheetNames();

        foreach (['products', 'colors', 'skus'] as $sheetName) {
            if (! in_array($sheetName, $sheetNames, true)) {
                $this->addFatalError("Hiányzó munkalap: {$sheetName}");
            }
        }
    }

    protected function validateRequiredColumns(?Worksheet $sheet, array $requiredColumns, string $sheetName): void
    {
        if (! $sheet) {
            $this->addFatalError("Hiányzó vagy nem olvasható munkalap: {$sheetName}");

            return;
        }

        $headerRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            true,
            true
        );

        $headers = collect($headerRow[1] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        foreach ($requiredColumns as $column) {
            if (! in_array($column, $headers, true)) {
                $this->addFatalError("{$sheetName}: hiányzó oszlop: {$column}");
            }
        }
    }

    protected function loadSheets(Spreadsheet $spreadsheet): void
    {
        $this->sheets = [
            'products' => $this->sheetToRows($spreadsheet->getSheetByName('products')),
            'colors' => $this->sheetToRows($spreadsheet->getSheetByName('colors')),
            'skus' => $this->sheetToRows($spreadsheet->getSheetByName('skus')),
            'assortments' => $this->sheetToRows($spreadsheet->getSheetByName('assortments')),
        ];
    }

    protected function validateRows(): void
    {
        $seasonCodes = Season::query()->whereNotNull('code')->pluck('code');
        $brandCodes = Brand::query()->whereNotNull('code')->pluck('code');
        $orderSheetTypeCodes = OrderSheetType::query()->whereNotNull('code')->pluck('code');
        $itemMainGroupCodes = ItemMainGroup::query()->whereNotNull('code')->pluck('code');
        $sizeRangeCodes = SizeRange::query()->whereNotNull('code')->pluck('code');
        $sizeCodes = Size::query()->whereNotNull('code')->pluck('code');

        $modelCodes = collect($this->sheets['products'] ?? [])
            ->map(fn ($row) => $this->nullIfEmpty($row['model_code'] ?? null))
            ->filter()
            ->values();

        $colorKeys = collect($this->sheets['colors'] ?? [])
            ->map(function ($row) {
                $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
                $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);

                return $modelCode && $colorCode ? "{$modelCode}|{$colorCode}" : null;
            })
            ->filter()
            ->values();

        $skuCodes = collect($this->sheets['skus'] ?? [])
            ->map(fn ($row) => $this->nullIfEmpty($row['sku_code'] ?? null))
            ->filter()
            ->values();

        $seenModelCodes = [];

        foreach ($this->sheets['products'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);

            if (! $modelCode) {
                $this->addRowError(null, "products {$rowNumber}. sor: hiányzó model_code");

                continue;
            }

            if (isset($seenModelCodes[$modelCode])) {
                $this->addRowError($modelCode, "products {$rowNumber}. sor: duplikált model_code: {$modelCode}");
            }

            $seenModelCodes[$modelCode] = true;

            $this->validateRequiredValue($modelCode, $row, 'name_hu', "products {$rowNumber}. sor: hiányzó name_hu");

            $this->validateCodeExists($modelCode, $seasonCodes, $row['season_code'] ?? null, "products {$rowNumber}. sor: nem létező season_code");
            $this->validateCodeExists($modelCode, $brandCodes, $row['brand_code'] ?? null, "products {$rowNumber}. sor: nem létező brand_code");
            $this->validateCodeExists($modelCode, $orderSheetTypeCodes, $row['order_sheet_type_code'] ?? null, "products {$rowNumber}. sor: nem létező order_sheet_type_code");
            $this->validateCodeExists($modelCode, $itemMainGroupCodes, $row['item_main_group_code'] ?? null, "products {$rowNumber}. sor: nem létező item_main_group_code");

            $sizeRangeCode = $this->nullIfEmpty($row['size_range_code'] ?? null);

            if ($sizeRangeCode && ! $sizeRangeCodes->contains($sizeRangeCode)) {
                $this->addRowError($modelCode, "products {$rowNumber}. sor: nem létező size_range_code: {$sizeRangeCode}");
            }
        }

        foreach ($this->sheets['colors'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);

            if (! $modelCode) {
                $this->addRowError(null, "colors {$rowNumber}. sor: hiányzó model_code");
            } elseif (! $modelCodes->contains($modelCode)) {
                $this->addRowError($modelCode, "colors {$rowNumber}. sor: a model_code nincs a products lapon: {$modelCode}");
            }

            if (! $colorCode) {
                $this->addRowError($modelCode, "colors {$rowNumber}. sor: hiányzó color_code");
            } elseif (strlen($colorCode) > 2) {
                $this->addRowError($modelCode, "colors {$rowNumber}. sor: a color_code maximum 2 karakter lehet: {$colorCode}");
            }

            $this->validateRequiredValue($modelCode, $row, 'name_hu', "colors {$rowNumber}. sor: hiányzó name_hu");

            $imageUrl = $this->nullIfEmpty($row['image_url'] ?? null);

            if (
                $imageUrl &&
                ! filter_var($imageUrl, FILTER_VALIDATE_URL) &&
                ! str_starts_with($imageUrl, 'color-images/')
            ) {
                $this->addRowError($modelCode, "colors {$rowNumber}. sor: hibás image_url: {$imageUrl}");
            }
        }

        $seenSkuCodes = [];

        foreach ($this->sheets['skus'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);
            $sizeCode = $this->nullIfEmpty($row['size_code'] ?? null);
            $skuCode = $this->nullIfEmpty($row['sku_code'] ?? null);

            if (! $modelCode) {
                $this->addRowError(null, "skus {$rowNumber}. sor: hiányzó model_code");
            } elseif (! $modelCodes->contains($modelCode)) {
                $this->addRowError($modelCode, "skus {$rowNumber}. sor: a model_code nincs a products lapon: {$modelCode}");
            }

            if (! $colorCode) {
                $this->addRowError($modelCode, "skus {$rowNumber}. sor: hiányzó color_code");
            } elseif (! $colorKeys->contains("{$modelCode}|{$colorCode}")) {
                $this->addRowError($modelCode, "skus {$rowNumber}. sor: a color_code nincs a colors lapon ehhez a modellhez: {$colorCode}");
            }

            if ($sizeCode && ! $sizeCodes->contains($sizeCode)) {
                $this->addRowError($modelCode, "skus {$rowNumber}. sor: nem létező size_code: {$sizeCode}");
            }

            if (! $skuCode) {
                $this->addRowError($modelCode, "skus {$rowNumber}. sor: hiányzó sku_code");
            } else {
                if (isset($seenSkuCodes[$skuCode])) {
                    $this->addRowError($modelCode, "skus {$rowNumber}. sor: duplikált sku_code az Excelben: {$skuCode}");
                }

                $seenSkuCodes[$skuCode] = true;
            }

            $this->validateRequiredValue($modelCode, $row, 'sku_name', "skus {$rowNumber}. sor: hiányzó sku_name");
        }

        foreach ($this->sheets['assortments'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $assortmentSkuCode = $this->nullIfEmpty($row['assortment_sku_code'] ?? null);
            $componentSkuCode = $this->nullIfEmpty($row['component_sku_code'] ?? null);
            $quantity = (int) ($row['quantity'] ?? 0);

            if (! $modelCode) {
                $this->addRowError(null, "assortments {$rowNumber}. sor: hiányzó model_code");
            } elseif (! $modelCodes->contains($modelCode)) {
                $this->addRowError($modelCode, "assortments {$rowNumber}. sor: a model_code nincs a products lapon: {$modelCode}");
            }

            if (! $assortmentSkuCode || ! $skuCodes->contains($assortmentSkuCode)) {
                $this->addRowError($modelCode, "assortments {$rowNumber}. sor: nem létező assortment_sku_code az Excel skus lapon: {$assortmentSkuCode}");
            }

            if (! $componentSkuCode || ! $skuCodes->contains($componentSkuCode)) {
                $this->addRowError($modelCode, "assortments {$rowNumber}. sor: nem létező component_sku_code az Excel skus lapon: {$componentSkuCode}");
            }

            if ($quantity <= 0) {
                $this->addRowError($modelCode, "assortments {$rowNumber}. sor: a quantity legyen pozitív egész szám");
            }
        }
    }

    protected function importProducts(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $seasons = Season::query()->whereNotNull('code')->get()->keyBy('code');
        $brands = Brand::query()->whereNotNull('code')->get()->keyBy('code');
        $types = OrderSheetType::query()->whereNotNull('code')->get()->keyBy('code');
        $groups = ItemMainGroup::query()->whereNotNull('code')->get()->keyBy('code');
        $sizeRanges = SizeRange::query()->whereNotNull('code')->get()->keyBy('code');

        foreach ($rows as $row) {
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);

            if (! $modelCode || $this->isInvalidModel($modelCode)) {
                $skipped++;
                continue;
            }

            $product = Product::firstOrNew(['model_code' => $modelCode]);
            $exists = $product->exists;

            $sizeRangeCode = $this->nullIfEmpty($row['size_range_code'] ?? null);

            $product->forceFill([
                'brand_id' => $brands->get($this->nullIfEmpty($row['brand_code'] ?? null))?->id,
                'order_sheet_type_id' => $types->get($this->nullIfEmpty($row['order_sheet_type_code'] ?? null))?->id,
                'season_id' => $seasons->get($this->nullIfEmpty($row['season_code'] ?? null))?->id,
                'item_main_group_id' => $groups->get($this->nullIfEmpty($row['item_main_group_code'] ?? null))?->id,
                'size_range_id' => $sizeRangeCode ? $sizeRanges->get($sizeRangeCode)?->id : null,
                'name_hu' => $row['name_hu'],
                'name_en' => $this->nullIfEmpty($row['name_en'] ?? null),
                'catalog_group_name_hu' => $this->nullIfEmpty($row['catalog_group_name_hu'] ?? null),
                'catalog_group_name_en' => $this->nullIfEmpty($row['catalog_group_name_en'] ?? null),
                'catalog_group_sort' => (int) ($row['catalog_group_sort'] ?? 0),
                'catalog_sort' => $this->nullIfEmpty($row['catalog_sort'] ?? null),
                'catalog_page' => $this->nullIfEmpty($row['catalog_page'] ?? null),
                'active' => $this->boolValue($row['active'] ?? true),
            ]);

            $product->save();

            $exists ? $updated++ : $created++;
        }

        $this->statistics['Termékek létrehozva'] = $created;
        $this->statistics['Termékek frissítve'] = $updated;
        $this->statistics['Termékek kihagyva'] = $skipped;
    }

    protected function importColors(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $imagesCreated = 0;
        $imagesUpdated = 0;
    
        $products = Product::query()
            ->whereNotNull('model_code')
            ->get()
            ->keyBy('model_code');
    
        foreach ($rows as $row) {
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);
    
            if (! $modelCode || ! $colorCode || $this->isInvalidModel($modelCode)) {
                $skipped++;
                continue;
            }
    
            $product = $products->get($modelCode);
    
            if (! $product) {
                $skipped++;
                continue;
            }
    
            $color = Color::firstOrNew([
                'product_id' => $product->id,
                'code' => $colorCode,
            ]);
    
            $exists = $color->exists;
    
            $color->forceFill([
                'name_hu' => $this->nullIfEmpty($row['name_hu'] ?? null) ?? $colorCode,
                'name_en' => $this->nullIfEmpty($row['name_en'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'active' => $this->boolValue($row['active'] ?? true),
            ]);
    
            $color->save();
    
            $exists ? $updated++ : $created++;
    
            $imageUrl = $this->nullIfEmpty($row['image_url'] ?? null);
    
            if ($imageUrl) {
                $colorImage = ColorImage::firstOrNew([
                    'product_id' => $product->id,
                    'color_id' => $color->id,
                    'image_url' => $imageUrl,
                ]);
    
                $imageExists = $colorImage->exists;
    
                $colorImage->forceFill([
                    'sort_order' => (int) ($row['sort_order'] ?? 10),
                    'active' => $this->boolValue($row['active'] ?? true),
                ]);
    
                $colorImage->save();
    
                $imageExists ? $imagesUpdated++ : $imagesCreated++;
            }
        }
    
        $this->statistics['Színek létrehozva'] = $created;
        $this->statistics['Színek frissítve'] = $updated;
        $this->statistics['Színek kihagyva'] = $skipped;
        $this->statistics['Színképek létrehozva'] = $imagesCreated;
        $this->statistics['Színképek frissítve'] = $imagesUpdated;
    }

    protected function importSkus(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $products = Product::query()->whereNotNull('model_code')->get()->keyBy('model_code');
        $sizes = Size::query()->whereNotNull('code')->get()->keyBy('code');

        $colors = Color::query()
            ->with('product')
            ->get()
            ->keyBy(fn (Color $color) => $color->product?->model_code . '|' . $color->code);

        foreach ($rows as $row) {
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);
            $skuCode = $this->nullIfEmpty($row['sku_code'] ?? null);

            if (! $modelCode || ! $colorCode || ! $skuCode || $this->isInvalidModel($modelCode)) {
                $skipped++;
                continue;
            }

            $product = $products->get($modelCode);
            $color = $colors->get("{$modelCode}|{$colorCode}");

            if (! $product || ! $color) {
                $skipped++;
                continue;
            }

            $sizeCode = $this->nullIfEmpty($row['size_code'] ?? null);

            $sku = Sku::firstOrNew(['sku_code' => $skuCode]);
            $exists = $sku->exists;

            $sku->forceFill([
                'product_id' => $product->id,
                'color_id' => $color->id,
                'size_id' => $sizeCode ? $sizes->get($sizeCode)?->id : null,
                'sku_name' => $row['sku_name'],
                'type' => $this->nullIfEmpty($row['type'] ?? null) ?: 'normal',
                'active' => $this->boolValue($row['active'] ?? true),
            ]);

            $sku->save();

            $exists ? $updated++ : $created++;
        }

        $this->statistics['SKU-k létrehozva'] = $created;
        $this->statistics['SKU-k frissítve'] = $updated;
        $this->statistics['SKU-k kihagyva'] = $skipped;
    }

    protected function importAssortments(array $rows): void
    {
        $loaded = 0;
        $skipped = 0;

        $products = Product::query()->whereNotNull('model_code')->get()->keyBy('model_code');

        $colors = Color::query()
            ->with('product')
            ->get()
            ->keyBy(fn (Color $color) => $color->product?->model_code . '|' . $color->code);

        $skus = Sku::query()->whereNotNull('sku_code')->get()->keyBy('sku_code');

        foreach ($rows as $row) {
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $colorCode = $this->nullIfEmpty($row['color_code'] ?? null);

            if (! $modelCode || ! $colorCode || $this->isInvalidModel($modelCode)) {
                $skipped++;
                continue;
            }

            $product = $products->get($modelCode);
            $color = $colors->get("{$modelCode}|{$colorCode}");
            $assortmentSku = $skus->get($this->nullIfEmpty($row['assortment_sku_code'] ?? null));
            $componentSku = $skus->get($this->nullIfEmpty($row['component_sku_code'] ?? null));

            if (! $product || ! $color || ! $assortmentSku || ! $componentSku) {
                $skipped++;
                continue;
            }

            DB::table('item_assortments')->updateOrInsert(
                [
                    'product_id' => $product->id,
                    'color_id' => $color->id,
                    'assortment_sku_id' => $assortmentSku->id,
                    'component_sku_id' => $componentSku->id,
                ],
                [
                    'quantity' => (int) $row['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $loaded++;
        }

        $this->statistics['Assortment sorok betöltve'] = $loaded;
        $this->statistics['Assortment sorok kihagyva'] = $skipped;
    }

    protected function sheetToRows(?Worksheet $sheet): array
    {
        if (! $sheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, true);

        if (empty($rows)) {
            return [];
        }

        $headerRow = array_shift($rows);

        $headers = [];

        foreach ($headerRow as $column => $value) {
            $header = trim((string) $value);

            if ($header !== '') {
                $headers[$column] = $header;
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $item = [];

            foreach ($headers as $column => $header) {
                $item[$header] = $row[$column] ?? null;
            }

            $hasValue = collect($item)
                ->filter(fn ($value) => trim((string) $value) !== '')
                ->isNotEmpty();

            if ($hasValue) {
                $result[] = $item;
            }
        }

        return $result;
    }

    protected function validateRequiredValue(?string $modelCode, array $row, string $column, string $message): void
    {
        if (! $this->nullIfEmpty($row[$column] ?? null)) {
            $this->addRowError($modelCode, $message);
        }
    }

    protected function validateCodeExists(?string $modelCode, $validCodes, mixed $value, string $message): void
    {
        $code = $this->nullIfEmpty($value);

        if (! $code) {
            $this->addRowError($modelCode, "{$message}: hiányzó érték");

            return;
        }

        if (! $validCodes->contains($code)) {
            $this->addRowError($modelCode, "{$message}: {$code}");
        }
    }

    protected function addFatalError(string $message): void
    {
        $this->fatalValidationErrors[] = $message;
    }

    protected function addRowError(?string $modelCode, string $message): void
    {
        $this->rowValidationErrors[] = $message;

        $modelCode = $this->nullIfEmpty($modelCode);

        if ($modelCode) {
            $this->invalidModelCodes[$modelCode] = true;
        }
    }

    protected function syncValidationErrors(): void
    {
        $this->validationErrors = array_merge(
            $this->fatalValidationErrors,
            $this->rowValidationErrors
        );
    }

    protected function isInvalidModel(?string $modelCode): bool
    {
        $modelCode = $this->nullIfEmpty($modelCode);

        return $modelCode && isset($this->invalidModelCodes[$modelCode]);
    }

    protected function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'igen', 'y'], true);
    }

    protected function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}