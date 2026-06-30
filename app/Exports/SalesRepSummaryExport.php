<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;    

class SalesRepSummaryExport implements
    FromCollection,
    WithHeadings,
    WithStyles,
    WithColumnFormatting,
    WithEvents,
    ShouldAutoSize
{
    
    protected int $detailRowCount = 0;
        
    public function __construct(
        protected Collection $rows
    ) {}
    
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
    
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
    
                $sheet = $event->sheet->getDelegate();
    
                $sheet->freezePane('A2');
    
                $summaryStartRow = $this->detailRowCount + 3;
    
                $lastRow = $sheet->getHighestRow();
    
                for ($row = $summaryStartRow; $row < $lastRow; $row++) {
    
                    $sheet->getStyle("A{$row}:M{$row}")
                        ->getFont()
                        ->setBold(true);
                }
    
                $sheet->getStyle("A{$lastRow}:M{$lastRow}")
                    ->getFont()
                    ->setBold(true);
    
                $sheet->getStyle("A{$lastRow}:M{$lastRow}")
                    ->getFill()
                    ->setFillType(
                        \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
                    );
    
                $sheet->getStyle("A{$lastRow}:M{$lastRow}")
                    ->getFill()
                    ->getStartColor()
                    ->setARGB('FFE5E7EB');
    
                $sheet->getStyle("A{$lastRow}:M{$lastRow}")
                    ->getBorders()
                    ->getOutline()
                    ->setBorderStyle(
                        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK
                    );
            },
        ];
    }
    
    public function columnFormats(): array
    {
        return [
            'I' => '#,##0',
            'J' => '#,##0.00',
            'K' => '#,##0.00',
            'L' => '#,##0.00',
            'M' => '#,##0.00',
        ];
    }
    
    
    public function headings(): array
    {
        return [
            __('partner.partner_code'),
            __('partner.partner'),
            __('partner.address'),
            __('partner.season'),
            __('partner.brand'),
            __('partner.order_sheet'),
            __('partner.status'),
            __('partner.currency'),
            __('partner.total_quantity'),
            __('partner.wholesale_value'),
            __('partner.retail_value'),
            __('partner.wholesale_value_huf'),
            __('partner.retail_value_huf'),
        ];
    }

    public function collection(): Collection
    {   

        $detailRows = $this->rows->map(fn ($row) => [
            $row['partner_code'],
            $row['partner_name'],
            $row['address_name'],
            $row['season'],
            $row['brand'],
            $row['type'],
            $row['status_label'],
            $row['currency'],
            $row['quantity'],
            $row['wholesale_value'],
            $row['retail_value'],
            $row['wholesale_value_huf'],
            $row['retail_value_huf'],
        ]);
        $this->detailRowCount = $detailRows->count();
        $summaryRows = collect();
    
        foreach ($this->rows->groupBy('currency') as $currency => $rows) {
            $summaryRows->push([
                __('partner.total_for_currencyl') . ' / ' . ($currency ?: __('partner.no_currency')),
                '',
                '',
                '',
                '',
                '',
                '',
                $currency,
                (int) $rows->sum('quantity'),
                (float) $rows->sum('wholesale_value'),
                (float) $rows->sum('retail_value'),
                (float) $rows->sum('wholesale_value_huf'),
                (float) $rows->sum('retail_value_huf'),
            ]);
        }
    
        $summaryRows->push([
            __('partner.grand_total'),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            (int) $this->rows->sum('quantity'),
            null,
            null,
            (float) $this->rows->sum('wholesale_value_huf'),
            (float) $this->rows->sum('retail_value_huf'),
        ]);
    
        return $detailRows
            ->push(['', '', '', '', '', '', '', '', '', '', '', '', ''])
            ->merge($summaryRows);
    }
}