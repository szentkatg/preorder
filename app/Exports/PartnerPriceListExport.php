<?php

namespace App\Exports;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PartnerPriceListExport implements WithMultipleSheets
{
    public function __construct(private readonly array $document) {}

    public function sheets(): array
    {
        $names = [];
        $sheets = [];
        foreach ($this->document['sections'] as $section) {
            $base = Str::limit(trim(preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $section['name']), " \t\n\r\0\x0B'"), 26, '');
            $base = $base ?: $this->document['captions']['title'];
            $name = $base;
            for ($number = 2; in_array(mb_strtolower($name), $names, true); $number++) {
                $name = mb_substr($base, 0, 30 - strlen((string) $number)).' '.$number;
            }
            $names[] = mb_strtolower($name);
            $sheets[] = new PartnerPriceListSheet($this->document, $section, $name);
        }

        return $sheets;
    }
}
