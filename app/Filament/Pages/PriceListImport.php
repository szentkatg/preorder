<?php

namespace App\Filament\Pages;

use App\Models\Currency;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PriceListImport extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Import';

    protected static ?string $navigationLabel = 'Árlista import';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.price-list-import';

    public ?array $data = [];

    public array $sheets = [];

    public array $fatalValidationErrors = [];

    public array $rowValidationErrors = [];

    public array $invalidPriceListCodes = [];

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
                    $spreadsheet->getSheetByName('price_lists'),
                    [
                        'code',
                        'name_hu',
                        'type',
                        'currency_code',
                        'active',
                    ],
                    'price_lists'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('price_list_items'),
                    [
                        'price_list_code',
                        'season_code',
                        'model_code',
                        'net_price',
                    ],
                    'price_list_items'
                );
            }

            if (! count($this->fatalValidationErrors)) {
                $this->loadSheets($spreadsheet);
                $this->validateRows();
            }

            $this->statistics = [
                'Validálás státusz' => count($this->fatalValidationErrors) ? 'Sikertelen' : 'Kész',
                'Fatális hibák' => count($this->fatalValidationErrors),
                'Sorhibák' => count($this->rowValidationErrors),
                'Hibás árlisták' => count($this->invalidPriceListCodes),
                'Árlista fej sorok' => count($this->sheets['price_lists'] ?? []),
                'Árlista tétel sorok' => count($this->sheets['price_list_items'] ?? []),
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
            $this->importPriceLists($this->sheets['price_lists'] ?? []);
            $this->importPriceListItems($this->sheets['price_list_items'] ?? []);
        });

        $this->statistics['Import státusz'] = 'Sikeres';
    }

    protected function resetImportState(): void
    {
        $this->sheets = [];
        $this->fatalValidationErrors = [];
        $this->rowValidationErrors = [];
        $this->invalidPriceListCodes = [];
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

        foreach (['price_lists', 'price_list_items'] as $sheetName) {
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
            'price_lists' => $this->sheetToRows($spreadsheet->getSheetByName('price_lists')),
            'price_list_items' => $this->sheetToRows($spreadsheet->getSheetByName('price_list_items')),
        ];
    }

    protected function validateRows(): void
    {
        $currencyCodes = Currency::query()->whereNotNull('code')->pluck('code');
        $seasonCodes = Season::query()->whereNotNull('code')->pluck('code');
        $productModelCodes = Product::query()->whereNotNull('model_code')->pluck('model_code');

        $priceListCodesInFile = collect($this->sheets['price_lists'] ?? [])
            ->map(fn ($row) => $this->nullIfEmpty($row['code'] ?? null))
            ->filter()
            ->values();

        $existingPriceListCodes = PriceList::query()
            ->whereNotNull('code')
            ->pluck('code');

        $validPriceListCodes = $priceListCodesInFile
            ->merge($existingPriceListCodes)
            ->unique()
            ->values();

        $seenPriceListCodes = [];

        foreach ($this->sheets['price_lists'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $code = $this->nullIfEmpty($row['code'] ?? null);
            $nameHu = $this->nullIfEmpty($row['name_hu'] ?? null);
            $type = $this->nullIfEmpty($row['type'] ?? null);
            $currencyCode = $this->nullIfEmpty($row['currency_code'] ?? null);
            $retailPriceListCode = $this->nullIfEmpty($row['retail_price_list_code'] ?? null);

            if (! $code) {
                $this->addRowError(null, "price_lists {$rowNumber}. sor: hiányzó code");
                continue;
            }

            if (isset($seenPriceListCodes[$code])) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: duplikált code az Excelben: {$code}");
            }

            $seenPriceListCodes[$code] = true;

            if (! $nameHu) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: hiányzó name_hu");
            }

            if (! $type) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: hiányzó type");
            }

            if (! $currencyCode) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: hiányzó currency_code");
            } elseif (! $currencyCodes->contains($currencyCode)) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: nem létező currency_code: {$currencyCode}");
            }

            if ($retailPriceListCode && ! $validPriceListCodes->contains($retailPriceListCode)) {
                $this->addRowError($code, "price_lists {$rowNumber}. sor: nem létező retail_price_list_code: {$retailPriceListCode}");
            }
        }

        $seenItemKeys = [];

        foreach ($this->sheets['price_list_items'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $priceListCode = $this->nullIfEmpty($row['price_list_code'] ?? null);
            $seasonCode = $this->nullIfEmpty($row['season_code'] ?? null);
            $modelCode = $this->nullIfEmpty($row['model_code'] ?? null);
            $netPrice = $this->nullIfEmpty($row['net_price'] ?? null);

            if (! $priceListCode) {
                $this->addRowError(null, "price_list_items {$rowNumber}. sor: hiányzó price_list_code");
                continue;
            }

            if (! $validPriceListCodes->contains($priceListCode)) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: nem létező price_list_code: {$priceListCode}");
            }

            if (! $seasonCode) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: hiányzó season_code");
            } elseif (! $seasonCodes->contains($seasonCode)) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: nem létező season_code: {$seasonCode}");
            }

            if (! $modelCode) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: hiányzó model_code");
            } elseif (! $productModelCodes->contains($modelCode)) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: nem létező model_code: {$modelCode}");
            }

            if ($netPrice === null) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: hiányzó net_price");
            } elseif (! is_numeric(str_replace(',', '.', $netPrice))) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: hibás net_price: {$netPrice}");
            }

            $itemKey = "{$priceListCode}|{$seasonCode}|{$modelCode}";

            if (isset($seenItemKeys[$itemKey])) {
                $this->addRowError($priceListCode, "price_list_items {$rowNumber}. sor: duplikált árlista tétel: {$itemKey}");
            }

            $seenItemKeys[$itemKey] = true;
        }
    }

    protected function importPriceLists(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $currencies = Currency::query()->whereNotNull('code')->get()->keyBy('code');
        $priceLists = PriceList::query()->whereNotNull('code')->get()->keyBy('code');

        foreach ($rows as $row) {
            $code = $this->nullIfEmpty($row['code'] ?? null);

            if (! $code || $this->isInvalidPriceList($code)) {
                $skipped++;
                continue;
            }

            $currencyCode = $this->nullIfEmpty($row['currency_code'] ?? null);
            $retailPriceListCode = $this->nullIfEmpty($row['retail_price_list_code'] ?? null);

            $priceList = PriceList::firstOrNew(['code' => $code]);
            $exists = $priceList->exists;

            $priceList->forceFill([
                'name_hu' => $row['name_hu'],
                'name_en' => $this->nullIfEmpty($row['name_en'] ?? null),
                'type' => $this->nullIfEmpty($row['type'] ?? null) ?: 'wholesale',
                'currency_id' => $currencyCode ? $currencies->get($currencyCode)?->id : null,
                'retail_price_list_id' => $retailPriceListCode ? $priceLists->get($retailPriceListCode)?->id : null,
                'active' => $this->boolValue($row['active'] ?? true),
            ]);

            $priceList->save();

            $priceLists->put($code, $priceList);

            $exists ? $updated++ : $created++;
        }

        $this->statistics['Árlisták létrehozva'] = $created;
        $this->statistics['Árlisták frissítve'] = $updated;
        $this->statistics['Árlisták kihagyva'] = $skipped;
    }

    protected function importPriceListItems(array $rows): void
    {
        $loaded = 0;
        $skipped = 0;

        $priceLists = PriceList::query()->whereNotNull('code')->get()->keyBy('code');
        $seasons = Season::query()->whereNotNull('code')->get()->keyBy('code');
        $products = Product::query()->whereNotNull('model_code')->get()->keyBy('model_code');

        foreach ($rows as $row) {
            $priceListCode = $this->nullIfEmpty($row['price_list_code'] ?? null);

            if (! $priceListCode || $this->isInvalidPriceList($priceListCode)) {
                $skipped++;
                continue;
            }

            $priceList = $priceLists->get($priceListCode);
            $season = $seasons->get($this->nullIfEmpty($row['season_code'] ?? null));
            $product = $products->get($this->nullIfEmpty($row['model_code'] ?? null));

            if (! $priceList || ! $season || ! $product) {
                $skipped++;
                continue;
            }

            $netPrice = (float) str_replace(',', '.', (string) $row['net_price']);

            PriceListItem::query()->updateOrCreate(
                [
                    'price_list_id' => $priceList->id,
                    'season_id' => $season->id,
                    'product_id' => $product->id,
                ],
                [
                    'net_price' => $netPrice,
                ]
            );

            $loaded++;
        }

        $this->statistics['Árlista tételek betöltve'] = $loaded;
        $this->statistics['Árlista tételek kihagyva'] = $skipped;
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

    protected function addFatalError(string $message): void
    {
        $this->fatalValidationErrors[] = $message;
    }

    protected function addRowError(?string $priceListCode, string $message): void
    {
        $this->rowValidationErrors[] = $message;

        $priceListCode = $this->nullIfEmpty($priceListCode);

        if ($priceListCode) {
            $this->invalidPriceListCodes[$priceListCode] = true;
        }
    }

    protected function syncValidationErrors(): void
    {
        $this->validationErrors = array_merge(
            $this->fatalValidationErrors,
            $this->rowValidationErrors
        );
    }

    protected function isInvalidPriceList(?string $priceListCode): bool
    {
        $priceListCode = $this->nullIfEmpty($priceListCode);

        return $priceListCode && isset($this->invalidPriceListCodes[$priceListCode]);
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