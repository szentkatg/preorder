<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SkuReplacementImportTemplate implements FromArray, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return ['season_code', 'old_sku_code', 'new_sku_code'];
    }

    public function title(): string
    {
        return 'Cikkcserék';
    }

    public function columnFormats(): array
    {
        return ['A:C' => '@'];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(28);
        }

        return [1 => ['font' => ['bold' => true]]];
    }
}
