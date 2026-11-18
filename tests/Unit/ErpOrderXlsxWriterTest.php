<?php

namespace Tests\Unit;

use App\Services\Partner\ErpOrderXlsxWriter;
use DateTimeImmutable;
use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use XMLReader;

class ErpOrderXlsxWriterTest extends TestCase
{
    public function test_fifty_thousand_rows_are_written_with_bounded_memory_and_exact_row_counts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-writer-test-');
        $date = new DateTimeImmutable('2026-11-18');
        $batches = (function () use ($date): Generator {
            for ($order = 1; $order <= 50; $order++) {
                yield [
                    'rows' => (function () use ($date, $order): Generator {
                        for ($sku = 1; $sku <= 1000; $sku++) {
                            yield ['HUPS', 'VRELO', $date, 'P000001', '000123', 'Teszt bolt',
                                null, null, null, null, 'REF-'.$order, '=Literal note', 'SKU-'.$sku,
                                'Termék '.$sku, null, null, null, null, 2, null, null, null, $date];
                        }
                    })(),
                    'audit' => [$order, 'S27', 'P000001', 'Partner', '000123', 'Teszt bolt', 'VRELO',
                        'REF-'.$order, 2000, 0, 0, null, 2000, null],
                ];
            }
        })();
        try {
            $startMemory = memory_get_usage(true);
            memory_reset_peak_usage();
            (new ErpOrderXlsxWriter)->write($batches, $path);
            $extraMemory = memory_get_peak_usage(true) - $startMemory;
            $this->assertLessThan(24 * 1024 * 1024, $extraMemory, 'Streaming XLSX must not build an in-memory cell collection.');

            $xml = new XMLReader;
            $this->assertTrue($xml->open('zip://'.str_replace('\\', '/', $path).'#xl/worksheets/sheet1.xml'));
            $rows = 0;
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                    $rows++;
                }
            }
            $xml->close();
            $this->assertSame(50001, $rows);
            // Read only the small audit sheet, not the 50,000-row data sheet.
            $reader = IOFactory::createReader('Xlsx');
            $reader->setLoadSheetsOnly(['Ellenőrzés']);
            $book = $reader->load($path);
            $audit = $book->getSheet(0);
            $this->assertSame(52, $audit->getHighestRow());
            $this->assertEquals(100000, $audit->getCell('L52')->getOldCalculatedValue());
            $this->assertEquals(100000, $audit->getCell('M52')->getOldCalculatedValue());
            $this->assertEquals(0, $audit->getCell('N52')->getOldCalculatedValue());
            $book->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
