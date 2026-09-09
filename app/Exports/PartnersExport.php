<?php

namespace App\Exports;

use App\Models\Partner;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class PartnersExport extends DefaultValueBinder implements FromQuery, WithCustomValueBinder, WithHeadings, WithMapping
{
    public function query()
    {
        return Partner::query()
            ->orderBy('name');
    }

    public function headings(): array
    {
        return [
            'ID',
            'Név',
            'ERP partnerkód',
            'Területi képviselő ERP partnerkód',
            'E-mail',
            'Telefon',
            'Aktív',
        ];
    }

    public function map($partner): array
    {
        return [
            $partner->id,
            $partner->name,
            (string) $partner->erp_partner_code,
            (string) $partner->sales_rep_erp_partner_code,
            $partner->email,
            (string) $partner->phone,
            $partner->active ? 'Igen' : 'Nem',
        ];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (in_array($cell->getColumn(), ['C', 'D', 'F'], true) && $cell->getRow() > 1) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
