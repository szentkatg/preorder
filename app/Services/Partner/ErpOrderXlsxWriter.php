<?php

namespace App\Services\Partner;

use App\Exports\ErpOrderAuditSheet;
use App\Exports\ErpOrderSheet;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;
use ZipArchive;

class ErpOrderXlsxWriter
{
    protected const MAX_ROWS = 1048576;

    /** Write one order at a time, without a workbook-sized cell collection. */
    public function write(iterable $batches, string $path): void
    {
        $options = new Options;
        $options->SHOULD_CREATE_NEW_SHEETS_AUTOMATICALLY = false;
        // Match the existing ERP files' text representation. OpenSpout spools this
        // string table to disk too; it does not retain a workbook-sized PHP map.
        $options->SHOULD_USE_INLINE_STRINGS = false;
        $options->DEFAULT_ROW_STYLE->setFontSize(11);
        $writer = new Writer($options);
        $header = (new Style)->setFontBold();
        $text = (new Style)->setFormat('@');
        $number = (new Style)->setFormat('0');
        $date = (new Style)->setFormat('yyyy.mm.dd');
        $note = (new Style)->setShouldWrapText();
        $dataStyles = [2 => $date, 3 => $text, 4 => $text, 10 => $text, 11 => $note, 12 => $text, 18 => $number, 22 => $date];
        $auditStyles = [2 => $text, 4 => $text, 7 => $text] + array_fill_keys(range(8, 13), $number);
        $dataWidths = $auditWidths = $cached = [];
        $dataRow = $auditRow = 1;
        $totals = array_fill(0, 6, 0);

        try {
            $writer->openToFile($path);
            $main = $writer->getCurrentSheet()->setName('Munka1')
                ->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('F')->setTabSelected(true));
            $mainHeaders = (new ErpOrderSheet([]))->headings();
            $writer->addRow($this->row($mainHeaders, [], $header));
            $this->measure($dataWidths, $mainHeaders);
            $audit = $writer->addNewSheetAndMakeItCurrent()->setName('Ellenőrzés')
                ->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('I'));
            $auditHeaders = (new ErpOrderAuditSheet([]))->headings();
            $writer->addRow($this->row($auditHeaders, [], $header));
            $this->measure($auditWidths, $auditHeaders);

            foreach ($batches as $batch) {
                $writer->setCurrentSheet($main);
                foreach ($batch['rows'] as $values) {
                    $this->checkRow(++$dataRow);
                    $writer->addRow($this->row($values, $dataStyles));
                    $this->measure($dataWidths, $values);
                }
                // Reserve the last possible row for the grand total.
                $this->checkRow(++$auditRow + 1);
                $values = $batch['audit'];
                $total = $values[8] + $values[9] + $values[10];
                $difference = $total - $values[12];
                $cached['L'.$auditRow] = $total;
                $cached['N'.$auditRow] = $difference;
                $values[11] = new FormulaCell('=SUM(I'.$auditRow.':K'.$auditRow.')', $number, $total);
                $values[13] = new FormulaCell('=L'.$auditRow.'-M'.$auditRow, $number, $difference);
                foreach ([$values[8], $values[9], $values[10], $total, $values[12], $difference] as $index => $value) {
                    $totals[$index] += $value;
                }
                $writer->setCurrentSheet($audit);
                $writer->addRow($this->row($values, $auditStyles));
                $this->measure($auditWidths, $values);
            }
            $values = array_fill(0, 14, null);
            $values[0] = 'ÖSSZESEN';
            foreach (range('I', 'N') as $index => $column) {
                $cached[$column.($auditRow + 1)] = $totals[$index];
                $values[$index + 8] = $auditRow > 1
                    ? new FormulaCell('=SUM('.$column.'2:'.$column.$auditRow.')', $number, $totals[$index])
                    : 0;
            }
            $writer->setCurrentSheet($audit);
            $writer->addRow($this->row($values, $auditStyles, $header));
            $main->setAutoFilter(new AutoFilter(0, 1, 22, $dataRow));
            $audit->setAutoFilter(new AutoFilter(0, 1, 13, $auditRow));
            foreach ($dataWidths as $index => $width) {
                $main->setColumnWidth($index === 11 ? 40 : $width, $index + 1);
            }
            foreach ($auditWidths as $index => $width) {
                $audit->setColumnWidth($width, $index + 1);
            }
            $writer->close();
            $this->cacheAuditFormulas($path, $cached, $auditRow > 1 ? count($cached) : 0);
        } catch (Throwable $exception) {
            // Close removes OpenSpout's scratch files. Never return a partial workbook.
            try {
                $writer->close();
            } catch (Throwable) {
                // Preserve the original validation or write failure.
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }

    protected function checkRow(int $row): void
    {
        if ($row > static::MAX_ROWS) {
            throw ValidationException::withMessages(['erpExport' => __('partner.erp_export_too_many_rows')]);
        }
    }

    private function row(array $values, array $styles = [], ?Style $rowStyle = null): Row
    {
        $cells = [];
        foreach ($values as $index => $value) {
            // fromValue treats '=' as a formula. All external text must be literal instead.
            $cells[] = $value instanceof Cell ? $value : (is_string($value)
                ? new StringCell($value, $styles[$index] ?? null)
                : Cell::fromValue($value, $styles[$index] ?? null));
        }

        return new Row($cells, $rowStyle);
    }

    private function measure(array &$widths, array $values): void
    {
        foreach ($values as $index => $value) {
            $length = $value instanceof DateTimeInterface ? 10
                : ($value instanceof Cell ? 12 : mb_strlen((string) $value));
            $widths[$index] = max($widths[$index] ?? 10, min(255, $length + 2));
        }
    }

    private function cacheAuditFormulas(string $path, array $values, int $expected): void
    {
        // OpenSpout 4 writes formulas without their computed values. Cache only the small
        // reconciliation sheet so readers without a calculation engine see the totals too.
        // The large Munka1 XML is never loaded into memory.
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to finalize ERP export.');
        }
        try {
            $xml = $zip->getFromName('xl/worksheets/sheet2.xml');
            if ($xml === false) {
                throw new RuntimeException('Missing ERP reconciliation worksheet.');
            }
            $xml = preg_replace_callback('/(<c\b[^>]*\br="([A-Z]+[0-9]+)"[^>]*><f>[^<]*<\/f>)(<\/c>)/',
                function (array $match) use ($values): string {
                    if (! array_key_exists($match[2], $values)) {
                        throw new RuntimeException('Unexpected ERP reconciliation formula.');
                    }

                    return $match[1].'<v>'.$values[$match[2]].'</v>'.$match[3];
                }, $xml, -1, $count);
            if ($count !== $expected || $xml === null || ! $zip->addFromString('xl/worksheets/sheet2.xml', $xml)) {
                throw new RuntimeException('Unable to cache ERP reconciliation totals.');
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Unable to save ERP reconciliation totals.');
            }
        }
    }
}
