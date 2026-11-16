<?php

namespace App\Exports;

use DateTimeInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ErpOrderSheet extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(protected array $rows) {}

    public function title(): string
    {
        return 'ERP rendelés';
    }

    public function headings(): array
    {
        return ['Vállalat', 'Document', 'Rend.dátum', 'Partnerkód', 'Címkód', 'BOLT', 'Pénznem',
            'Száll.mód', 'Fiz.mód', 'Fiz.feltétel', 'Vevői ref.', 'Megjegyzés', 'Cikkszám', 'Megnevezés',
            'Főcsoport név', 'Főcsop.t.1 név', 'Főcsop.t.2 név', 'EAN-kód', 'Mennyiség', 'Ár', 'Engedmény', 'Raktár', 'Száll.hidő'];
    }

    public function array(): array
    {
        return array_map(fn ($row) => array_map(fn ($value) => $value instanceof DateTimeInterface ? Date::dateTimeToExcel($value) : $value, $row), $this->rows);
    }

    public function columnFormats(): array
    {
        return ['C' => 'yyyy.mm.dd', 'D' => '@', 'E' => '@', 'K' => '@', 'M' => '@', 'S' => '0', 'W' => 'yyyy.mm.dd'];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            // Codes, references and user-entered notes are always literal text.
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('F2');
        $sheet->setAutoFilter('A1:W'.max(1, count($this->rows) + 1));

        return [1 => ['font' => ['bold' => true]]];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $sheet->getColumnDimension('L')->setAutoSize(false)->setWidth(40);
            $sheet->getStyle('L1:L'.$sheet->getHighestRow())->getAlignment()->setWrapText(true);
        }];
    }
}
