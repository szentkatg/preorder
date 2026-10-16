<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class PartnerPriceListSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    private array $groupRows = [];

    private array $productRows = [];

    private int $lastRow = 6;

    public function __construct(private readonly array $document, private readonly array $section, private readonly string $sheetTitle) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        $this->groupRows = $this->productRows = [];
        $captions = $this->document['captions'];
        $headings = [$captions['code'], $captions['name'], $captions['category'], $this->document['net_label'].' ('.$this->document['currency'].')'];
        if ($this->document['show_retail']) {
            $headings[] = $captions['retail'].' ('.$this->document['currency'].')';
        }
        $headings[] = $captions['delivery'];
        $headings[] = $captions['page'];
        $rows = [[null], [$captions['title'].' - '.$this->document['season']], [$this->section['name']],
            [$this->document['price_list_code'].($this->document['retail_price_list_code'] ? ' / '.$this->document['retail_price_list_code'] : '')], [null], $headings];
        $group = null;
        foreach ($this->section['rows'] as $product) {
            if ($group !== $product['main_group']) {
                $group = $product['main_group'];
                if ($group !== '') {
                    $this->groupRows[] = count($rows) + 1;
                    $rows[] = [$group];
                }
            }
            $line = [$product['code'], $product['name'], $product['catalog_group'], $product['net_price'] ?? '-'];
            if ($this->document['show_retail']) {
                $line[] = $product['retail_price'] ?? '-';
            }
            $line[] = $product['delivery_date'] ? Date::dateTimeToExcel($product['delivery_date']) : '-';
            $line[] = $product['catalog_page'] ?? '-';
            $rows[] = $line;
            $this->productRows[] = count($rows);
        }
        $rows[] = [null];
        $rows[] = [$captions['missing']];
        $this->lastRow = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastColumn = $this->document['show_retail'] ? 'G' : 'F';
            $dateColumn = $this->document['show_retail'] ? 'F' : 'E';
            $sheet->setShowGridlines(false);
            $sheet->getStyle('A1:'.$lastColumn.$this->lastRow)->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            foreach ([2, 3, 4, $this->lastRow] as $row) {
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            }
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A3')->getFont()->setBold(true);
            $sheet->getStyle('A6:'.$lastColumn.'6')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27364B']],
                'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension(6)->setRowHeight(34);
            $widths = $this->document['show_retail'] ? [20, 24, 27, 18, 20, 20, 14] : [20, 24, 27, 18, 20, 14];
            foreach ($widths as $index => $width) {
                $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
            }
            foreach ($this->groupRows as $row) {
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray(['font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF3']]]);
                $sheet->getRowDimension($row)->setRowHeight(21);
            }
            foreach ($this->productRows as $row) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('D8DDE5');
                $sheet->getStyle('A'.$row.':C'.$row)->getAlignment()->setWrapText(true);
                $sheet->getStyle('D'.$row.':'.($this->document['show_retail'] ? 'E' : 'D').$row)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('D'.$row.':'.$lastColumn.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle($dateColumn.$row)->getNumberFormat()->setFormatCode($this->document['captions']['excel_date_format']);
                $sheet->getRowDimension($row)->setRowHeight(-1);
            }
            $sheet->getStyle('A'.$this->lastRow)->getFont()->setItalic(true)->setSize(9);
            $sheet->freezePane('C7');
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(2, 6)->setPrintArea('A2:'.$lastColumn.$this->lastRow);
            $sheet->getHeaderFooter()->setOddFooter('&L'.$this->document['season_code'].'&R'.$this->document['captions']['page_number'].' &P / &N');
        }];
    }
}
