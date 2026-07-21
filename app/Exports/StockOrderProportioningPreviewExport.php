<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOrderProportioningPreviewExport
{
    /**
     * @param array<string, mixed> $preview
     */
    public function download(array $preview): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($preview);
        $filename = 'keszletrendeles-aranyositas-elonezet-'
            . now()->format('Ymd-His')
            . '.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' =>
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
            ]
        );
    }

    /**
     * @param array<string, mixed> $preview
     */
    protected function buildSpreadsheet(array $preview): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Arányosítás előnézet');

        $headers = [
            'Modellkód',
            'Terméknév',
            'Színkód',
            'Színnév',
            'Méret',
            'SKU',
            'Számítási mód',
            'Kerekítési módszertan',
            'Gyűjtők bevonása',
            'Kerekítési többszörös',
            'Kerekítési mód',
            'Felfelé kerekítés maradéktól',
            'Normál partneri rendelés',
            'Gyűjtős partneri rendelés',
            'Partneri rendelés összesen',
            'Arány',
            'Arányösszeg',
            'Eredeti készletterv összesen',
            'Kiosztott készlet kerekítés előtt',
            'Új készletmennyiség',
            'Végleges összmennyiség',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        $groups = $preview['groups'] ?? [];

        if (! is_array($groups)) {
            $groups = [];
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $items = $group['items'] ?? [];

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sheet->fromArray([
                    $group['model_code'] ?? '',
                    $group['product_name'] ?? '',
                    $group['color_code'] ?? '',
                    $group['color_name'] ?? '',
                    $item['size_code'] ?? '',
                    $item['sku_code'] ?? '',
                    $this->calculationModeLabel(
                        (string) ($group['calculation_mode'] ?? '')
                    ),
                    $group['rounding_method_label'] ?? '',
                    $this->booleanLabel(
                        $group['include_assortments'] ?? null
                    ),
                    $group['rounding_multiple'] ?? '',
                    $group['rounding_mode'] ?? '',
                    $group['round_up_from_remainder'] ?? '',
                    $item['partner_direct_quantity'] ?? 0,
                    $item['partner_assortment_quantity'] ?? 0,
                    $item['partner_quantity'] ?? 0,
                    $item['ratio'] ?? 0,
                    $item['ratio_sum'] ?? 0,
                    $group['planned_stock_total'] ?? 0,
                    $item['raw_allocated_stock'] ?? 0,
                    $item['new_stock_quantity'] ?? 0,
                    $item['final_quantity'] ?? 0,
                ], null, 'A' . $row);

                $row++;
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(
            count($headers)
        );
        $lastRow = max(1, $row - 1);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

        $sheet->getStyle("A1:{$lastColumn}1")
            ->getFont()
            ->setBold(true);

        $sheet->getStyle("A1:{$lastColumn}1")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE5E7EB');

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()
            ->setARGB('FFD1D5DB');

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A1:{$lastColumn}1")
            ->getAlignment()
            ->setWrapText(true);

        foreach (range(1, count($headers)) as $columnIndex) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    protected function calculationModeLabel(string $mode): string
    {
        return match ($mode) {
            'zero_balance' => 'Nullszaldós korrekció',
            'zero_ratio' => 'Nulla arányösszeg',
            'proportioning' => 'Arányosítás és kerekítés',
            default => $mode,
        };
    }

    protected function booleanLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (bool) $value ? 'Igen' : 'Nem';
    }
}
