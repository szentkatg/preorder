<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportTemplateSheet implements FromArray, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly string $name,
        private readonly array $headers,
        private readonly array $rows = [],
        private readonly bool $guide = false,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function title(): string
    {
        return $this->name;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            // Whole-column formats also apply to rows typed later in Excel.
            $formats[$column.':'.$column] = match ($header) {
                'active', 'allow_assortment_ordering', 'sort_order', 'catalog_group_sort', 'catalog_sort', 'catalog_page', 'quantity' => '0',
                'net_price', 'purchase_price' => '0.0000',
                'promised_delivery_date' => 'yyyy.mm.dd',
                default => '@',
            };
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A2');
        foreach ($this->headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $width = $this->guide ? [29, 38, 90][$index] : min(38, max(18, strlen($header) + 2));
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getFont()->setName('Arial')->setSize(11);
        if ($this->guide) {
            $sheet->getStyle('A2:C'.$sheet->getHighestRow())->getAlignment()->setWrapText(true)->setVertical('center');
            foreach (range(2, $sheet->getHighestRow()) as $row) {
                $sheet->getRowDimension($row)->setRowHeight(44);
            }
        }

        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E293B']],
            'borders' => ['inside' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'FFFFFF']]],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]];
    }
}
