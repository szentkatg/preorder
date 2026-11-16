<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

class ErpOrderExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    public function __construct(public readonly array $rows, public readonly array $auditRows) {}

    public function sheets(): array
    {
        return [new ErpOrderSheet($this->rows), new ErpOrderAuditSheet($this->auditRows)];
    }
}
