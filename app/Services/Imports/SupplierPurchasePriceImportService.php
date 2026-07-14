<?php

namespace App\Services\Imports;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SupplierPurchasePriceImportService
{
    public function __construct(
        protected SpreadsheetHelper $spreadsheetHelper,
        protected SupplierPurchasePriceValidator $validator,
        protected SupplierImporter $supplierImporter,
        protected ProductPurchasePriceImporter $purchasePriceImporter,
    ) {
    }

    /**
     * @return array{
     *     fatalErrors: array<int, string>,
     *     rowErrors: array<int, string>,
     *     sheets: array<string, array<int, array<string, mixed>>>,
     *     statistics: array<string, mixed>
     * }
     */
    public function validate(mixed $file): array
    {
        try {
            $spreadsheet = $this->spreadsheetHelper->loadFromUpload($file);

            return $this->validator->validate($spreadsheet);
        } catch (\Throwable $e) {
            return [
                'fatalErrors' => [
                    $e->getMessage(),
                ],
                'rowErrors' => [],
                'sheets' => [],
                'statistics' => [
                    'Validálás státusz' => 'Sikertelen',
                    'Fatális hibák' => 1,
                    'Sorhibák' => 0,
                    'Hiba fájl' => $e->getFile(),
                    'Hiba sor' => $e->getLine(),
                ],
            ];
        }
    }

    /**
     * @return array{
     *     fatalErrors: array<int, string>,
     *     rowErrors: array<int, string>,
     *     sheets: array<string, array<int, array<string, mixed>>>,
     *     statistics: array<string, mixed>
     * }
     */
    public function import(mixed $file): array
    {
        $validationResult = $this->validate($file);

        if (
            $validationResult['fatalErrors'] !== []
            || $validationResult['rowErrors'] !== []
        ) {
            $validationResult['statistics']['Import státusz'] =
                'Nem indult el validációs hibák miatt';

            return $validationResult;
        }

        try {
            $importStatistics = DB::transaction(function () use (
                $validationResult
            ): array {
                $supplierStatistics = $this->supplierImporter->import(
                    $validationResult['sheets']['suppliers'] ?? []
                );

                /*
                 * A beszállító import után fut az árimport, ezért az ugyanebben
                 * az Excelben létrehozott beszállítók már elérhetők lesznek.
                 */
                $purchasePriceStatistics =
                    $this->purchasePriceImporter->import(
                        $validationResult['sheets']
                            ['product_purchase_prices'] ?? []
                    );

                return [
                    'supplier' => $supplierStatistics,
                    'purchasePrice' => $purchasePriceStatistics,
                ];
            });

            $validationResult['statistics'] = array_merge(
                $validationResult['statistics'],
                [
                    'Beszállítók létrehozva' =>
                        $importStatistics['supplier']['created'],
                    'Beszállítók frissítve' =>
                        $importStatistics['supplier']['updated'],
                    'Beszállítók változatlanok' =>
                        $importStatistics['supplier']['unchanged'],
                    'Beszállítók kihagyva' =>
                        $importStatistics['supplier']['skipped'],

                    'Beszerzési árak létrehozva' =>
                        $importStatistics['purchasePrice']['created'],
                    'Beszerzési árak frissítve' =>
                        $importStatistics['purchasePrice']['updated'],
                    'Beszerzési árak változatlanok' =>
                        $importStatistics['purchasePrice']['unchanged'],
                    'Beszerzési árak kihagyva' =>
                        $importStatistics['purchasePrice']['skipped'],

                    'Import státusz' => 'Sikeres',
                ]
            );

            return $validationResult;
        } catch (\Throwable $e) {
            $validationResult['fatalErrors'][] =
                'Az importálás sikertelen: ' . $e->getMessage();

            $validationResult['statistics']['Import státusz'] =
                'Sikertelen';

            $validationResult['statistics']['Hiba fájl'] =
                $e->getFile();

            $validationResult['statistics']['Hiba sor'] =
                $e->getLine();

            return $validationResult;
        }
    }
}