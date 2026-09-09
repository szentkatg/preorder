<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PartnerMasterImport extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Import';

    protected static ?string $navigationLabel = 'Partner import';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.partner-master-import';

    public ?array $data = [];

    public array $sheets = [];

    public array $fatalValidationErrors = [];

    public array $rowValidationErrors = [];

    public array $invalidPartnerCodes = [];

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
                    $spreadsheet->getSheetByName('partners'),
                    ['erp_partner_code', 'name', 'active'],
                    'partners'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('partner_addresses'),
                    [
                        'erp_partner_code',
                        'addrid',
                        'name',
                        'language_code',
                        'currency_code',
                        'price_list_code',
                        'active',
                    ],
                    'partner_addresses'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('partner_users'),
                    [
                        'erp_partner_code',
                        'name',
                        'email',
                        'role',
                        'active',
                    ],
                    'partner_users'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('address_brands'),
                    [
                        'erp_partner_code',
                        'addrid',
                        'brand_code',
                    ],
                    'address_brands'
                );

                $this->validateRequiredColumns(
                    $spreadsheet->getSheetByName('address_order_sheet_types'),
                    [
                        'erp_partner_code',
                        'addrid',
                        'order_sheet_type_code',
                    ],
                    'address_order_sheet_types'
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
                'Hibás partnerek' => count($this->invalidPartnerCodes),
                'Partners sorok' => count($this->sheets['partners'] ?? []),
                'Partner cím sorok' => count($this->sheets['partner_addresses'] ?? []),
                'Partner user sorok' => count($this->sheets['partner_users'] ?? []),
                'Cím-brand kapcsolat sorok' => count($this->sheets['address_brands'] ?? []),
                'Cím-rendelési lap típus kapcsolat sorok' => count($this->sheets['address_order_sheet_types'] ?? []),
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
            $this->importPartners($this->sheets['partners'] ?? []);
            $this->importPartnerAddresses($this->sheets['partner_addresses'] ?? []);
            $this->importPartnerUsers($this->sheets['partner_users'] ?? []);
            $this->importAddressBrands($this->sheets['address_brands'] ?? []);
            $this->importAddressOrderSheetTypes($this->sheets['address_order_sheet_types'] ?? []);
        });

        $this->statistics['Import státusz'] = 'Sikeres';
    }

    protected function resetImportState(): void
    {
        $this->sheets = [];
        $this->fatalValidationErrors = [];
        $this->rowValidationErrors = [];
        $this->invalidPartnerCodes = [];
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

            $this->statistics = [
                'file_type' => get_debug_type($file),
                'raw_file_value' => $this->data['file'] ?? null,
            ];

            return null;
        }

        return IOFactory::load($file->getRealPath());
    }

    protected function validateRequiredSheets(Spreadsheet $spreadsheet): void
    {
        $sheetNames = $spreadsheet->getSheetNames();

        $requiredSheets = [
            'partners',
            'partner_addresses',
            'partner_users',
            'address_brands',
            'address_order_sheet_types',
        ];

        foreach ($requiredSheets as $sheetName) {
            if (! in_array($sheetName, $sheetNames, true)) {
                $this->addFatalError("Hiányzó munkalap: {$sheetName}");
            }
        }
    }

    protected function validateRequiredColumns(
        ?Worksheet $sheet,
        array $requiredColumns,
        string $sheetName
    ): void {
        if (! $sheet) {
            $this->addFatalError("Hiányzó vagy nem olvasható munkalap: {$sheetName}");

            return;
        }

        $headerRow = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestColumn().'1',
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
            'partners' => $this->sheetToRows($spreadsheet->getSheetByName('partners')),
            'partner_addresses' => $this->sheetToRows($spreadsheet->getSheetByName('partner_addresses')),
            'partner_users' => $this->sheetToRows($spreadsheet->getSheetByName('partner_users')),
            'address_brands' => $this->sheetToRows($spreadsheet->getSheetByName('address_brands')),
            'address_order_sheet_types' => $this->sheetToRows($spreadsheet->getSheetByName('address_order_sheet_types')),
        ];
    }

    protected function validateRows(): void
    {
        $partnerCodesInFile = collect($this->sheets['partners'] ?? [])
            ->map(fn ($row) => $this->nullIfEmpty($row['erp_partner_code'] ?? null))
            ->filter()
            ->values();

        $existingPartnerCodes = Partner::query()
            ->whereNotNull('erp_partner_code')
            ->pluck('erp_partner_code');

        $validPartnerCodes = $partnerCodesInFile
            ->merge($existingPartnerCodes)
            ->unique()
            ->values();

        $addridsInFile = collect($this->sheets['partner_addresses'] ?? [])
            ->map(fn ($row) => $this->nullIfEmpty($row['addrid'] ?? null))
            ->filter()
            ->values();

        $existingAddrids = PartnerAddress::query()
            ->whereNotNull('addrid')
            ->pluck('addrid');

        $validAddrids = $addridsInFile
            ->merge($existingAddrids)
            ->unique()
            ->values();

        $priceListCodes = PriceList::query()->whereNotNull('code')->pluck('code');
        $currencyCodes = Currency::query()->whereNotNull('code')->pluck('code');
        $languageCodes = Language::query()->whereNotNull('code')->pluck('code');
        $brandCodes = Brand::query()->whereNotNull('code')->pluck('code');
        $orderSheetTypeCodes = OrderSheetType::query()->whereNotNull('code')->pluck('code');

        $seenPartnerCodes = [];
        foreach ($this->sheets['partners'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $salesRepCode = $this->nullIfEmpty($row['sales_rep_erp_partner_code'] ?? null);
            $name = $this->nullIfEmpty($row['name'] ?? null);

            if (! $partnerCode) {
                $this->addRowError(null, "partners {$rowNumber}. sor: hiányzó erp_partner_code");

                continue;
            }

            if (isset($seenPartnerCodes[$partnerCode])) {
                $this->addRowError($partnerCode, "partners {$rowNumber}. sor: duplikált erp_partner_code: {$partnerCode}");
            }

            $seenPartnerCodes[$partnerCode] = true;

            if (! $name) {
                $this->addRowError($partnerCode, "partners {$rowNumber}. sor: hiányzó név");
            }

            if ($salesRepCode && ! $validPartnerCodes->contains($salesRepCode)) {
                $this->addRowError(
                    $partnerCode,
                    "partners {$rowNumber}. sor: nem létező területi képviselő ERP kód: {$salesRepCode}"
                );
            }
        }

        foreach ($this->sheets['partner_addresses'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $name = $this->nullIfEmpty($row['name'] ?? null);
            $priceListCode = $this->nullIfEmpty($row['price_list_code'] ?? null);
            $currencyCode = $this->nullIfEmpty($row['currency_code'] ?? null);
            $languageCode = $this->nullIfEmpty($row['language_code'] ?? null);

            if (! $partnerCode) {
                $this->addRowError(null, "partner_addresses {$rowNumber}. sor: hiányzó erp_partner_code");
            } elseif (! $validPartnerCodes->contains($partnerCode)) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: nem létező partner kód: {$partnerCode}");
            }

            if (! $addrid) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: hiányzó addrid");
            }

            if (! $name) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: hiányzó név");
            }

            if (! $priceListCode) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: hiányzó price_list_code");
            } elseif (! $priceListCodes->contains($priceListCode)) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: nem létező árlista kód: {$priceListCode}");
            }

            if (! $currencyCode) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: hiányzó currency_code");
            } elseif (! $currencyCodes->contains($currencyCode)) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: nem létező pénznem kód: {$currencyCode}");
            }

            if (! $languageCode) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: hiányzó language_code");
            } elseif (! $languageCodes->contains($languageCode)) {
                $this->addRowError($partnerCode, "partner_addresses {$rowNumber}. sor: nem létező nyelv kód: {$languageCode}");
            }
        }

        $seenEmails = [];
        $existingUsersByEmail = PartnerUser::query()
            ->whereNotNull('email')
            ->get()
            ->keyBy('email');

        foreach ($this->sheets['partner_users'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $email = $this->nullIfEmpty($row['email'] ?? null);
            $name = $this->nullIfEmpty($row['name'] ?? null);
            $password = $this->nullIfEmpty($row['password'] ?? null);

            if (! $partnerCode) {
                $this->addRowError(null, "partner_users {$rowNumber}. sor: hiányzó erp_partner_code");
            } elseif (! $validPartnerCodes->contains($partnerCode)) {
                $this->addRowError($partnerCode, "partner_users {$rowNumber}. sor: nem létező partner kód: {$partnerCode}");
            }

            if (! $name) {
                $this->addRowError($partnerCode, "partner_users {$rowNumber}. sor: hiányzó név");
            }

            if (! $email) {
                $this->addRowError($partnerCode, "partner_users {$rowNumber}. sor: hiányzó email");
            } else {
                if (isset($seenEmails[$email])) {
                    $this->addRowError($partnerCode, "partner_users {$rowNumber}. sor: duplikált email az Excelben: {$email}");
                }

                $seenEmails[$email] = true;

                if (! $existingUsersByEmail->has($email) && ! $password) {
                    $this->addRowError($partnerCode, "partner_users {$rowNumber}. sor: új felhasználónál kötelező a password mező: {$email}");
                }
            }
        }

        foreach ($this->sheets['address_brands'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $brandCode = $this->nullIfEmpty($row['brand_code'] ?? null);

            if (! $partnerCode) {
                $this->addRowError(null, "address_brands {$rowNumber}. sor: hiányzó erp_partner_code");
            } elseif (! $validPartnerCodes->contains($partnerCode)) {
                $this->addRowError($partnerCode, "address_brands {$rowNumber}. sor: nem létező partner kód: {$partnerCode}");
            }

            if (! $addrid) {
                $this->addRowError($partnerCode, "address_brands {$rowNumber}. sor: hiányzó addrid");
            } elseif (! $validAddrids->contains($addrid)) {
                $this->addRowError($partnerCode, "address_brands {$rowNumber}. sor: nem létező címkód: {$addrid}");
            }

            if (! $brandCode) {
                $this->addRowError($partnerCode, "address_brands {$rowNumber}. sor: hiányzó brand_code");
            } elseif (! $brandCodes->contains($brandCode)) {
                $this->addRowError($partnerCode, "address_brands {$rowNumber}. sor: nem létező márka kód: {$brandCode}");
            }
        }

        foreach ($this->sheets['address_order_sheet_types'] ?? [] as $index => $row) {
            $rowNumber = $index + 2;
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $typeCode = $this->nullIfEmpty($row['order_sheet_type_code'] ?? null);

            if (! $partnerCode) {
                $this->addRowError(null, "address_order_sheet_types {$rowNumber}. sor: hiányzó erp_partner_code");
            } elseif (! $validPartnerCodes->contains($partnerCode)) {
                $this->addRowError($partnerCode, "address_order_sheet_types {$rowNumber}. sor: nem létező partner kód: {$partnerCode}");
            }

            if (! $addrid) {
                $this->addRowError($partnerCode, "address_order_sheet_types {$rowNumber}. sor: hiányzó addrid");
            } elseif (! $validAddrids->contains($addrid)) {
                $this->addRowError($partnerCode, "address_order_sheet_types {$rowNumber}. sor: nem létező címkód: {$addrid}");
            }

            if (! $typeCode) {
                $this->addRowError($partnerCode, "address_order_sheet_types {$rowNumber}. sor: hiányzó order_sheet_type_code");
            } elseif (! $orderSheetTypeCodes->contains($typeCode)) {
                $this->addRowError($partnerCode, "address_order_sheet_types {$rowNumber}. sor: nem létező rendelési lap típus kód: {$typeCode}");
            }
        }
    }

    protected function importPartners(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $code = $this->nullIfEmpty($row['erp_partner_code'] ?? null);

            if (! $code || $this->isInvalidPartner($code)) {
                $skipped++;

                continue;
            }

            $partner = Partner::firstOrNew([
                'erp_partner_code' => $code,
            ]);

            $exists = $partner->exists;

            $attributes = [
                'name' => $row['name'],
                'email' => $this->nullIfEmpty($row['email'] ?? null),
                'phone' => $this->nullIfEmpty($row['phone'] ?? null),
                'active' => $this->boolValue($row['active'] ?? true),
            ];

            if (array_key_exists('sales_rep_erp_partner_code', $row)) {
                $attributes['sales_rep_erp_partner_code'] = $this->nullIfEmpty(
                    $row['sales_rep_erp_partner_code']
                );
            }

            $partner->forceFill($attributes);

            $partner->save();

            $exists ? $updated++ : $created++;
        }

        $this->statistics['Partnerek létrehozva'] = $created;
        $this->statistics['Partnerek frissítve'] = $updated;
        $this->statistics['Partnerek kihagyva'] = $skipped;
    }

    protected function importPartnerAddresses(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $partners = Partner::query()
            ->whereNotNull('erp_partner_code')
            ->get()
            ->keyBy('erp_partner_code');

        $priceLists = PriceList::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy('code');

        $currencies = Currency::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy('code');

        $languages = Language::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy('code');

        foreach ($rows as $row) {
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);

            if (! $addrid || ! $partnerCode || $this->isInvalidPartner($partnerCode)) {
                $skipped++;

                continue;
            }

            $partner = $partners->get($partnerCode);

            if (! $partner) {
                $skipped++;

                continue;
            }

            $priceListCode = $this->nullIfEmpty($row['price_list_code'] ?? null);
            $currencyCode = $this->nullIfEmpty($row['currency_code'] ?? null);
            $languageCode = $this->nullIfEmpty($row['language_code'] ?? null);

            $address = PartnerAddress::firstOrNew([
                'addrid' => $addrid,
            ]);

            $exists = $address->exists;

            $address->forceFill([
                'partner_id' => $partner->id,
                'name' => $row['name'],
                'country' => $this->nullIfEmpty($row['country'] ?? null),
                'zip' => $this->nullIfEmpty($row['zip'] ?? null),
                'city' => $this->nullIfEmpty($row['city'] ?? null),
                'street' => $this->nullIfEmpty($row['street'] ?? null),
                'contact_name' => $this->nullIfEmpty($row['contact_name'] ?? null),
                'email' => $this->nullIfEmpty($row['email'] ?? null),
                'phone' => $this->nullIfEmpty($row['phone'] ?? null),
                'allow_assortment_ordering' => $this->boolValue($row['allow_assortment_ordering'] ?? false),
                'price_list_id' => $priceListCode ? $priceLists->get($priceListCode)?->id : null,
                'currency_id' => $currencyCode ? $currencies->get($currencyCode)?->id : null,
                'language_id' => $languageCode ? $languages->get($languageCode)?->id : null,
                'active' => $this->boolValue($row['active'] ?? true),
            ]);

            $address->save();

            $exists ? $updated++ : $created++;
        }

        $this->statistics['Partner címek létrehozva'] = $created;
        $this->statistics['Partner címek frissítve'] = $updated;
        $this->statistics['Partner címek kihagyva'] = $skipped;
    }

    protected function importPartnerUsers(array $rows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $partners = Partner::query()
            ->whereNotNull('erp_partner_code')
            ->get()
            ->keyBy('erp_partner_code');

        foreach ($rows as $row) {
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $email = $this->nullIfEmpty($row['email'] ?? null);

            if (! $partnerCode || ! $email || $this->isInvalidPartner($partnerCode)) {
                $skipped++;

                continue;
            }

            $partner = $partners->get($partnerCode);

            if (! $partner) {
                $skipped++;

                continue;
            }

            $user = PartnerUser::firstOrNew([
                'email' => $email,
            ]);

            $exists = $user->exists;

            $values = [
                'partner_id' => $partner->id,
                'name' => $row['name'],
                'role' => $this->nullIfEmpty($row['role'] ?? null) ?: 'partner_admin',
                'active' => $this->boolValue($row['active'] ?? true),
            ];

            $password = $this->nullIfEmpty($row['password'] ?? null);

            if ($password) {
                $values['password'] = Hash::make($password);
            }

            $user->forceFill($values);
            $user->save();

            $exists ? $updated++ : $created++;
        }

        $this->statistics['Partner userek létrehozva'] = $created;
        $this->statistics['Partner userek frissítve'] = $updated;
        $this->statistics['Partner userek kihagyva'] = $skipped;
    }

    protected function importAddressBrands(array $rows): void
    {
        $createdOrUpdated = 0;
        $skipped = 0;

        $addresses = PartnerAddress::query()
            ->whereNotNull('addrid')
            ->get()
            ->keyBy('addrid');

        $brands = Brand::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy('code');

        foreach ($rows as $row) {
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $brandCode = $this->nullIfEmpty($row['brand_code'] ?? null);

            if (! $partnerCode || ! $addrid || ! $brandCode || $this->isInvalidPartner($partnerCode)) {
                $skipped++;

                continue;
            }

            $address = $addresses->get($addrid);
            $brand = $brands->get($brandCode);

            if (! $address || ! $brand) {
                $skipped++;

                continue;
            }

            DB::table('partner_address_brand')->updateOrInsert(
                [
                    'partner_address_id' => $address->id,
                    'brand_id' => $brand->id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $createdOrUpdated++;
        }

        $this->statistics['Cím-brand kapcsolatok betöltve'] = $createdOrUpdated;
        $this->statistics['Cím-brand kapcsolatok kihagyva'] = $skipped;
    }

    protected function importAddressOrderSheetTypes(array $rows): void
    {
        $createdOrUpdated = 0;
        $skipped = 0;

        $addresses = PartnerAddress::query()
            ->whereNotNull('addrid')
            ->get()
            ->keyBy('addrid');

        $types = OrderSheetType::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy('code');

        foreach ($rows as $row) {
            $partnerCode = $this->nullIfEmpty($row['erp_partner_code'] ?? null);
            $addrid = $this->nullIfEmpty($row['addrid'] ?? null);
            $typeCode = $this->nullIfEmpty($row['order_sheet_type_code'] ?? null);

            if (! $partnerCode || ! $addrid || ! $typeCode || $this->isInvalidPartner($partnerCode)) {
                $skipped++;

                continue;
            }

            $address = $addresses->get($addrid);
            $type = $types->get($typeCode);

            if (! $address || ! $type) {
                $skipped++;

                continue;
            }

            DB::table('partner_address_order_sheet_type')->updateOrInsert(
                [
                    'partner_address_id' => $address->id,
                    'order_sheet_type_id' => $type->id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $createdOrUpdated++;
        }

        $this->statistics['Cím-rendelési lap kapcsolatok betöltve'] = $createdOrUpdated;
        $this->statistics['Cím-rendelési lap kapcsolatok kihagyva'] = $skipped;
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

    protected function addRowError(?string $partnerCode, string $message): void
    {
        $this->rowValidationErrors[] = $message;

        $partnerCode = $this->nullIfEmpty($partnerCode);

        if ($partnerCode) {
            $this->invalidPartnerCodes[$partnerCode] = true;
        }
    }

    protected function syncValidationErrors(): void
    {
        $this->validationErrors = array_merge(
            $this->fatalValidationErrors,
            $this->rowValidationErrors
        );
    }

    protected function isInvalidPartner(?string $partnerCode): bool
    {
        $partnerCode = $this->nullIfEmpty($partnerCode);

        return $partnerCode && isset($this->invalidPartnerCodes[$partnerCode]);
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
