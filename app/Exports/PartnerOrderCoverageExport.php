<?php

namespace App\Exports;

use App\Services\PartnerOrderCoverageService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PartnerOrderCoverageExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    protected array $coverage;

    public function __construct(
        protected ?int $seasonId = null,
        protected string $locale = 'hu',
    ) {
        app()->setLocale($this->locale);

        $this->coverage = app(PartnerOrderCoverageService::class)
            ->build($this->seasonId);
    }

    public function title(): string
    {
        return __('partner.partner_order_coverage');
    }

    public function array(): array
    {
        $rows = [];

        $rows[] = $this->brandHeaderRow();
        $rows[] = $this->typeHeaderRow();
        $rows[] = $this->quantityTotalRow();
        $rows[] = $this->filledCountRow();

        foreach ($this->coverage['rows'] as $row) {
            $line = [
                $row['partner_code'],
                $row['partner_name'],
                $row['address_name'],
            ];

            foreach ($this->coverage['brands'] as $brand) {
                foreach ($brand['columns'] as $column) {
                    $cell = $row['cells'][$column['index']] ?? null;

                    if (! $cell || ! $cell['enabled']) {
                        $line[] = '-';
                    } else {
                        $line[] = (int) ($cell['quantity'] ?? 0);
                    }
                }

                $line[] = (int) ($row['brand_totals'][$brand['id']] ?? 0);
            }

            $line[] = (int) ($row['grand_total'] ?? 0);

            $rows[] = $line;
        }

        return $rows;
    }

    protected function brandHeaderRow(): array
    {
        $row = [
            __('partner.partner_code'),
            __('partner.partner'),
            __('partner.address'),
        ];

        foreach ($this->coverage['brands'] as $brand) {
            foreach ($brand['columns'] as $column) {
                $row[] = $brand['name'];
            }

            $row[] = __('partner.total');
        }

        $row[] = __('partner.grand_total');

        return $row;
    }

    protected function typeHeaderRow(): array
    {
        $row = ['', '', ''];

        foreach ($this->coverage['brands'] as $brand) {
            foreach ($brand['columns'] as $column) {
                $row[] = $column['order_sheet'];
            }

            $row[] = __('partner.total');
        }

        $row[] = __('partner.grand_total');

        return $row;
    }

    protected function quantityTotalRow(): array
    {
        $row = [
            '',
            __('partner.total_quantity'),
            '',
        ];

        foreach ($this->coverage['brands'] as $brand) {
            foreach ($brand['columns'] as $column) {
                $row[] = (int) ($this->coverage['totals']['columns'][$column['index']] ?? 0);
            }

            $row[] = (int) ($this->coverage['totals']['brands'][$brand['id']] ?? 0);
        }

        $row[] = (int) ($this->coverage['totals']['grand_total'] ?? 0);

        return $row;
    }

    protected function filledCountRow(): array
    {
        $row = [
            '',
            __('partner.filled_order_sheets'),
            '',
        ];

        foreach ($this->coverage['brands'] as $brand) {
            foreach ($brand['columns'] as $column) {
                $row[] = (int) ($this->coverage['totals']['filled_counts'][$column['index']] ?? 0);
            }

            $row[] = (int) ($this->coverage['totals']['brand_filled_counts'][$brand['id']] ?? 0);
        }

        $row[] = (int) ($this->coverage['totals']['grand_filled_count'] ?? 0);

        return $row;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastColumnIndex = count($this->array()[0]);
                $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
                $lastRow = count($this->array());

                $sheet->freezePane('D5');
                $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");

                $sheet->getStyle("A1:{$lastColumn}4")->getFont()->setBold(true);
                $sheet->getStyle("A1:{$lastColumn}4")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getStyle("A1:{$lastColumn}1")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFE5E7EB');

                $sheet->getStyle("A3:{$lastColumn}3")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFDBEAFE');

                $sheet->getStyle("A4:{$lastColumn}4")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFE0E7FF');

                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()
                    ->setARGB('FFD1D5DB');

                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
                    ->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getColumnDimension('A')->setWidth(16);
                $sheet->getColumnDimension('B')->setWidth(32);
                $sheet->getColumnDimension('C')->setWidth(36);

                for ($row = 5; $row <= $lastRow; $row++) {
                    $excelColumn = 4;

                    foreach ($this->coverage['brands'] as $brand) {
                        foreach ($brand['columns'] as $column) {
                            $cellData = $this->coverage['rows'][$row - 5]['cells'][$column['index']] ?? null;
                            $columnLetter = Coordinate::stringFromColumnIndex($excelColumn);

                            $color = match ($cellData['status'] ?? 'disabled') {
                                'missing' => 'FFFEE2E2',
                                'empty' => 'FFFEF3C7',
                                'filled' => 'FFDCFCE7',
                                default => 'FFE5E7EB',
                            };

                            $sheet->getStyle("{$columnLetter}{$row}")
                                ->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()
                                ->setARGB($color);

                            $excelColumn++;
                        }

                        $totalColumn = Coordinate::stringFromColumnIndex($excelColumn);

                        $sheet->getStyle("{$totalColumn}{$row}")
                            ->getFont()
                            ->setBold(true);

                        $sheet->getStyle("{$totalColumn}{$row}")
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setARGB('FFDBEAFE');

                        $excelColumn++;
                    }

                    $grandColumn = Coordinate::stringFromColumnIndex($excelColumn);

                    $sheet->getStyle("{$grandColumn}{$row}")
                        ->getFont()
                        ->setBold(true);

                    $sheet->getStyle("{$grandColumn}{$row}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFBFDBFE');
                }
            },
        ];
    }
}