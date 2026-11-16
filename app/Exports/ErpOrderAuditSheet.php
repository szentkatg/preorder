<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ErpOrderAuditSheet extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(protected array $rows) {}

    public function title(): string
    {
        return 'Ellenőrzés';
    }

    public function headings(): array
    {
        return ['Rendelés azonosító', 'Szezon', 'Partnerkód', 'Partner', 'Címkód', 'BOLT', 'Document', 'Vevői ref.',
            'Változatlan db', 'Cserélt db', 'Törölt db', 'TOTAL', 'Rendelt db', 'Eltérés'];
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->rows as $index => $row) {
            $excelRow = $index + 2;
            $row[11] = '=SUM(I'.$excelRow.':K'.$excelRow.')';
            $row[13] = '=L'.$excelRow.'-M'.$excelRow;
            $rows[] = $row;
        }
        $last = count($rows) + 1;
        $total = array_fill(0, 14, null);
        $total[0] = 'ÖSSZESEN';
        foreach (range('I', 'N') as $index => $column) {
            $total[$index + 8] = $last >= 2 ? '=SUM('.$column.'2:'.$column.$last.')' : 0;
        }
        $rows[] = $total;

        return $rows;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        // Only our six numeric reconciliation columns can contain formulas.
        if (is_string($value) && ! (in_array($cell->getColumn(), ['I', 'J', 'K', 'L', 'M', 'N'], true) && str_starts_with($value, '='))) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return ['C' => '@', 'E' => '@', 'H' => '@', 'I' => '0', 'J' => '0', 'K' => '0', 'L' => '0', 'M' => '0', 'N' => '0'];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('I2');
        $sheet->setAutoFilter('A1:N'.(count($this->rows) + 1));

        return [1 => ['font' => ['bold' => true]], count($this->rows) + 2 => ['font' => ['bold' => true]]];
    }
}
