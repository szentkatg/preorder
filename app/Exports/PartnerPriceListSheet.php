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

    private array $footerRows = [];

    private int $noteRow = 5;

    private int $lastRow = 5;

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
        $this->groupRows = $this->productRows = $this->footerRows = [];
        $captions = $this->document['captions'];
        $headings = [$captions['code'], $captions['name'], $this->document['net_label'].' ('.$this->document['currency'].')'];
        if ($this->document['show_retail']) {
            $headings[] = $captions['retail'].' ('.$this->document['currency'].')';
        }
        $headings[] = $captions['delivery'];
        $headings[] = $captions['page'];
        $rows = [[null], [$captions['title'].' - '.$this->document['season']], [$this->section['name']], [null], $headings];
        $group = null;
        foreach ($this->section['rows'] as $product) {
            if ($group !== $product['catalog_group']) {
                $group = $product['catalog_group'];
                if ($group !== '') {
                    $this->groupRows[] = count($rows) + 1;
                    $rows[] = [$group];
                }
            }
            $line = [$product['code'], $product['name'], $product['net_price'] ?? '-'];
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
        $this->noteRow = count($rows);
        $rows[] = [null];
        foreach ($this->document['footer_lines'] as $line) {
            $rows[] = [$line];
            $this->footerRows[] = count($rows);
        }
        $this->lastRow = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastColumn = $this->document['show_retail'] ? 'F' : 'E';
            $dateColumn = $this->document['show_retail'] ? 'E' : 'D';
            $sheet->setShowGridlines(false);
            $sheet->getStyle('A1:'.$lastColumn.$this->lastRow)->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            foreach ([2, 3, $this->noteRow, ...$this->footerRows] as $row) {
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            }
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A3')->getFont()->setBold(true);
            $sheet->getStyle('A5:'.$lastColumn.'5')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27364B']],
                'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension(5)->setRowHeight(34);
            $widths = $this->document['show_retail'] ? [18, 22, 15, 16, 17, 12] : [18, 26, 17, 21, 18];
            foreach ($widths as $index => $width) {
                $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
            }
            foreach ($this->groupRows as $row) {
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray(['font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF3']]]);
                $sheet->getRowDimension($row)->setRowHeight(18);
            }
            foreach ($this->productRows as $row) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('D8DDE5');
                $sheet->getStyle('A'.$row.':B'.$row)->getAlignment()->setWrapText(true);
                $sheet->getStyle('C'.$row.':'.($this->document['show_retail'] ? 'D' : 'C').$row)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('C'.$row.':'.$lastColumn.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle($dateColumn.$row)->getNumberFormat()->setFormatCode($this->document['captions']['excel_date_format']);
                $sheet->getRowDimension($row)->setRowHeight(-1);
            }
            $sheet->getStyle('A'.$this->noteRow)->getFont()->setItalic(true)->setSize(9);
            foreach ($this->footerRows as $row) {
                $sheet->getStyle('A'.$row)->getFont()->setSize(9);
                $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);
                $sheet->getRowDimension($row)->setRowHeight(17);
            }
            $sheet->freezePane('C6');
            $sheet->getPageMargins()->setLeft(0.25)->setRight(0.25)->setTop(0.35)->setBottom(0.35);
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(2, 5)->setPrintArea('A2:'.$lastColumn.$this->lastRow);
            $codes = $this->document['price_list_code'].($this->document['retail_price_list_code'] ? ' / '.$this->document['retail_price_list_code'] : '');
            $escapeFooter = static fn (string $text) => str_replace('&', '&&', $text);
            $footer = '&L'.$escapeFooter($this->document['season_code']).'&C&"Arial,Regular"&7&K8A919B'.$escapeFooter($codes)
                .'&R&"Arial,Regular"&8&K555555'.$escapeFooter($this->document['captions']['page_number']).' &P / &N';
            $sheet->getHeaderFooter()->setOddFooter($footer)->setEvenFooter($footer);
        }];
    }
}
