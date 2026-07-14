<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class SpreadsheetHelper
{
    /**
     * Betölti a Filament FileUpload által átadott ideiglenes Excel-fájlt.
     */
    public function loadFromUpload(mixed $file): Spreadsheet
    {
        if (is_array($file)) {
            $file = reset($file);
        }

        if (! $file) {
            throw new RuntimeException('Nincs kiválasztott fájl.');
        }

        if (! method_exists($file, 'getRealPath')) {
            throw new RuntimeException(
                'A feltöltött fájl nem olvasható. Töltsd fel újra az Excel-fájlt.'
            );
        }

        $path = $file->getRealPath();

        if (! $path || ! is_file($path)) {
            throw new RuntimeException(
                'A feltöltött Excel-fájl ideiglenes példánya nem található.'
            );
        }

        return IOFactory::load($path);
    }

    /**
     * Ellenőrzi, hogy minden kötelező munkalap megtalálható-e.
     *
     * @return array<int, string>
     */
    public function validateRequiredSheets(
        Spreadsheet $spreadsheet,
        array $requiredSheets
    ): array {
        $errors = [];
        $sheetNames = $spreadsheet->getSheetNames();

        foreach ($requiredSheets as $sheetName) {
            if (! in_array($sheetName, $sheetNames, true)) {
                $errors[] = "Hiányzó munkalap: {$sheetName}";
            }
        }

        return $errors;
    }

    /**
     * Ellenőrzi egy munkalap kötelező oszlopait.
     *
     * @return array<int, string>
     */
    public function validateRequiredColumns(
        ?Worksheet $sheet,
        array $requiredColumns,
        string $sheetName
    ): array {
        if (! $sheet) {
            return [
                "Hiányzó vagy nem olvasható munkalap: {$sheetName}",
            ];
        }

        $headers = $this->headers($sheet);
        $errors = [];

        foreach ($requiredColumns as $column) {
            if (! in_array($column, $headers, true)) {
                $errors[] = "{$sheetName}: hiányzó oszlop: {$column}";
            }
        }

        return $errors;
    }

    /**
     * Visszaadja az első sorban található nem üres fejléceket.
     *
     * @return array<int, string>
     */
    public function headers(Worksheet $sheet): array
    {
        $headerRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            true,
            true
        );

        return collect($headerRow[1] ?? [])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Egy munkalapot asszociatív tömbbé alakít.
     *
     * Az első sor az oszlopneveket tartalmazza.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sheetToRows(?Worksheet $sheet): array
    {
        if (! $sheet) {
            return [];
        }

        $rows = $sheet->toArray(
            null,
            true,
            true,
            true
        );

        if ($rows === []) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = [];

        foreach ($headerRow as $column => $value) {
            $header = trim((string) $value);

            if ($header !== '') {
                $headers[$column] = $header;
            }
        }

        $result = [];

        foreach ($rows as $excelRowNumber => $row) {
            $item = [];

            foreach ($headers as $column => $header) {
                $item[$header] = $row[$column] ?? null;
            }

            $hasValue = collect($item)
                ->contains(
                    fn (mixed $value): bool =>
                        trim((string) $value) !== ''
                );

            if (! $hasValue) {
                continue;
            }

            /*
             * Az eredeti Excel-sorszámot eltesszük, hogy a validátor
             * pontos hibaüzenetet tudjon megjeleníteni.
             */
            $item['_row_number'] = $excelRowNumber;

            $result[] = $item;
        }

        return $result;
    }

    public function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function boolValue(
        mixed $value,
        bool $default = true
    ): bool {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(
            trim((string) $value)
        );

        return in_array(
            $normalized,
            [
                '1',
                'true',
                'yes',
                'igen',
                'y',
                'i',
            ],
            true
        );
    }

    /**
     * Elfogadja például a 12,50 és a 12.50 formátumot is.
     */
    public function decimalValue(mixed $value): ?float
    {
        $value = $this->nullIfEmpty($value);

        if ($value === null) {
            return null;
        }

        /*
         * Szóközök és nem törő szóközök eltávolítása.
         */
        $normalized = str_replace(
            [" ", "\u{00A0}"],
            '',
            $value
        );

        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}