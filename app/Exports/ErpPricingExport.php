<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ErpPricingExport extends DefaultValueBinder implements FromArray, WithColumnFormatting, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Import';
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return ['A' => '@', 'F:AD' => '#,##0.00'];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $sheet->freezePane('F4');
            $sheet->getStyle('A1:AD3')->getFont()->setBold(true);
            $sheet->getStyle('F1:AD3')->getAlignment()->setWrapText(true);
            $sheet->getStyle('F1:AD3')->getAlignment()->setHorizontal('center')->setVertical('center');
            $sheet->getRowDimension(2)->setRowHeight(30);
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(26);
            $sheet->getColumnDimension('E')->setWidth(3);
            for ($i = 6; $i <= 30; $i++) {
                $sheet->getColumnDimensionByColumn($i)->setWidth(17);
            }
            $sheet->getStyle('F4:AD'.max(4, count($this->rows)))->getAlignment()->setHorizontal('right');
        }];
    }
}
