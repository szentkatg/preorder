<?php

namespace App\Services\Imports;

use App\Models\Color;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Supplier;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SupplierPurchasePriceValidator
{
    public function __construct(
        protected SpreadsheetHelper $spreadsheetHelper,
    ) {
    }

    /**
     * @return array{
     *     fatalErrors: array<int, string>,
     *     rowErrors: array<int, string>,
     *     sheets: array<string, array<int, array<string, mixed>>>,
     *     statistics: array<string, int|string>
     * }
     */
    public function validate(Spreadsheet $spreadsheet): array
    {
        $fatalErrors = [];
        $rowErrors = [];
        $sheets = [];

        $sheetNames = $spreadsheet->getSheetNames();

        $hasSuppliers = in_array('suppliers', $sheetNames, true);
        $hasPurchasePrices = in_array(
            'product_purchase_prices',
            $sheetNames,
            true
        );

        if (! $hasSuppliers && ! $hasPurchasePrices) {
            $fatalErrors[] =
                'Az Excelnek legalább a suppliers vagy a '
                . 'product_purchase_prices munkalapot tartalmaznia kell.';
        }

        if ($hasSuppliers) {
            $supplierSheet = $spreadsheet->getSheetByName('suppliers');

            $fatalErrors = array_merge(
                $fatalErrors,
                $this->spreadsheetHelper->validateRequiredColumns(
                    $supplierSheet,
                    [
                        'erp_partner_code',
                        'name',
                        'short_name',
                        'active',
                    ],
                    'suppliers'
                )
            );

            if ($supplierSheet) {
                $sheets['suppliers'] =
                    $this->spreadsheetHelper->sheetToRows($supplierSheet);
            }
        }

        if ($hasPurchasePrices) {
            $purchasePriceSheet = $spreadsheet->getSheetByName(
                'product_purchase_prices'
            );

            $fatalErrors = array_merge(
                $fatalErrors,
                $this->spreadsheetHelper->validateRequiredColumns(
                    $purchasePriceSheet,
                    [
                        'model_code',
                        'color_code',
                        'supplier_code',
                        'currency_code',
                        'purchase_price',
                        'active',
                    ],
                    'product_purchase_prices'
                )
            );

            if ($purchasePriceSheet) {
                $sheets['product_purchase_prices'] =
                    $this->spreadsheetHelper->sheetToRows(
                        $purchasePriceSheet
                    );
            }
        }

        if ($fatalErrors !== []) {
            return [
                'fatalErrors' => array_values(array_unique($fatalErrors)),
                'rowErrors' => [],
                'sheets' => $sheets,
                'statistics' => [
                    'Validálás státusz' => 'Sikertelen',
                    'Fatális hibák' => count($fatalErrors),
                    'Sorhibák' => 0,
                    'Beszállító sorok' => count(
                        $sheets['suppliers'] ?? []
                    ),
                    'Beszerzési ár sorok' => count(
                        $sheets['product_purchase_prices'] ?? []
                    ),
                ],
            ];
        }

        $rowErrors = array_merge(
            $this->validateSupplierRows($sheets['suppliers'] ?? []),
            $this->validatePurchasePriceRows(
                $sheets['product_purchase_prices'] ?? [],
                $sheets['suppliers'] ?? []
            )
        );

        return [
            'fatalErrors' => [],
            'rowErrors' => $rowErrors,
            'sheets' => $sheets,
            'statistics' => [
                'Validálás státusz' =>
                    $rowErrors === [] ? 'Sikeres' : 'Sorhibákkal kész',
                'Fatális hibák' => 0,
                'Sorhibák' => count($rowErrors),
                'Beszállító sorok' => count(
                    $sheets['suppliers'] ?? []
                ),
                'Beszerzési ár sorok' => count(
                    $sheets['product_purchase_prices'] ?? []
                ),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    protected function validateSupplierRows(array $rows): array
    {
        $errors = [];
        $seenCodes = [];

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);

            $erpPartnerCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['erp_partner_code'] ?? null
            );

            $name = $this->spreadsheetHelper->nullIfEmpty(
                $row['name'] ?? null
            );

            if (! $erpPartnerCode) {
                $errors[] =
                    "suppliers {$rowNumber}. sor: hiányzó "
                    . 'erp_partner_code';

                continue;
            }

            $normalizedCode = mb_strtoupper($erpPartnerCode);

            if (isset($seenCodes[$normalizedCode])) {
                $errors[] =
                    "suppliers {$rowNumber}. sor: duplikált "
                    . "erp_partner_code az Excelben: {$erpPartnerCode}";
            }

            $seenCodes[$normalizedCode] = true;

            if (! $name) {
                $errors[] =
                    "suppliers {$rowNumber}. sor: hiányzó name "
                    . "({$erpPartnerCode})";
            }

            if (! $this->isValidOptionalBoolean($row['active'] ?? null)) {
                $active = trim((string) ($row['active'] ?? ''));

                $errors[] =
                    "suppliers {$rowNumber}. sor: hibás active érték: "
                    . $active;
            }
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $supplierRows
     *
     * @return array<int, string>
     */
    protected function validatePurchasePriceRows(
        array $rows,
        array $supplierRows
    ): array {
        $errors = [];
        $seenKeys = [];

        $products = Product::query()
            ->whereNotNull('model_code')
            ->get(['id', 'model_code'])
            ->keyBy(
                fn (Product $product): string =>
                    mb_strtoupper(trim($product->model_code))
            );

        $currencies = Currency::query()
            ->whereNotNull('code')
            ->get(['id', 'code'])
            ->keyBy(
                fn (Currency $currency): string =>
                    mb_strtoupper(trim($currency->code))
            );

        $databaseSupplierCodes = Supplier::query()
            ->whereNotNull('erp_partner_code')
            ->pluck('erp_partner_code')
            ->map(
                fn (mixed $code): string =>
                    mb_strtoupper(trim((string) $code))
            );

        $excelSupplierCodes = collect($supplierRows)
            ->map(
                fn (array $row): ?string =>
                    $this->spreadsheetHelper->nullIfEmpty(
                        $row['erp_partner_code'] ?? null
                    )
            )
            ->filter()
            ->map(
                fn (string $code): string =>
                    mb_strtoupper(trim($code))
            );

        $validSupplierCodes = $databaseSupplierCodes
            ->merge($excelSupplierCodes)
            ->unique()
            ->values();

        $colorKeys = Color::query()
            ->with('product:id,model_code')
            ->whereNotNull('code')
            ->get(['id', 'product_id', 'code'])
            ->mapWithKeys(function (Color $color): array {
                $modelCode = $color->product?->model_code;

                if (! $modelCode) {
                    return [];
                }

                $key = mb_strtoupper(
                    trim($modelCode) . '|' . trim($color->code)
                );

                return [$key => $color->id];
            });

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['_row_number'] ?? 0);

            $modelCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['model_code'] ?? null
            );

            $colorCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['color_code'] ?? null
            );

            $supplierCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['supplier_code'] ?? null
            );

            $currencyCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['currency_code'] ?? null
            );

            $purchasePrice = $this->spreadsheetHelper->decimalValue(
                $row['purchase_price'] ?? null
            );

            if (! $modelCode) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . 'hiányzó model_code';
            } else {
                $normalizedModelCode = mb_strtoupper($modelCode);

                if (! $products->has($normalizedModelCode)) {
                    $errors[] =
                        "product_purchase_prices {$rowNumber}. sor: "
                        . "nem létező model_code: {$modelCode}";
                }
            }

            if (! $supplierCode) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . 'hiányzó supplier_code';
            } elseif (
                ! $validSupplierCodes->contains(
                    mb_strtoupper($supplierCode)
                )
            ) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . "nem létező supplier_code: {$supplierCode}";
            }

            if (! $currencyCode) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . 'hiányzó currency_code';
            } elseif (
                ! $currencies->has(mb_strtoupper($currencyCode))
            ) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . "nem létező currency_code: {$currencyCode}";
            }

            if ($purchasePrice === null) {
                $rawPrice = trim(
                    (string) ($row['purchase_price'] ?? '')
                );

                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . "hibás purchase_price: {$rawPrice}";
            } elseif ($purchasePrice < 0) {
                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . 'a purchase_price nem lehet negatív';
            }

            if ($modelCode && $colorCode) {
                $colorKey = mb_strtoupper(
                    trim($modelCode) . '|' . trim($colorCode)
                );

                if (! $colorKeys->has($colorKey)) {
                    $errors[] =
                        "product_purchase_prices {$rowNumber}. sor: "
                        . "a {$colorCode} szín nem tartozik a "
                        . "{$modelCode} modellhez";
                }
            }

            if (
                $modelCode
                && $supplierCode
            ) {
                $duplicateKey = mb_strtoupper(
                    implode('|', [
                        trim($modelCode),
                        trim($colorCode ?? ''),
                        trim($supplierCode),
                    ])
                );

                if (isset($seenKeys[$duplicateKey])) {
                    $errors[] =
                        "product_purchase_prices {$rowNumber}. sor: "
                        . 'duplikált beszerzési ár az Excelben: '
                        . $duplicateKey;
                }

                $seenKeys[$duplicateKey] = true;
            }

            if (! $this->isValidOptionalBoolean($row['active'] ?? null)) {
                $active = trim((string) ($row['active'] ?? ''));

                $errors[] =
                    "product_purchase_prices {$rowNumber}. sor: "
                    . "hibás active érték: {$active}";
            }
        }

        return $errors;
    }

    protected function isValidOptionalBoolean(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        if (is_bool($value)) {
            return true;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return in_array(
            $normalized,
            [
                '0',
                '1',
                'true',
                'false',
                'yes',
                'no',
                'igen',
                'nem',
                'y',
                'n',
                'i',
            ],
            true
        );
    }
}