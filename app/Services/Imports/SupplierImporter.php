<?php

namespace App\Services\Imports;

use App\Models\Supplier;

class SupplierImporter
{
    public function __construct(
        protected SpreadsheetHelper $spreadsheetHelper,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: int
     * }
     */
    public function import(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $erpPartnerCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['erp_partner_code'] ?? null
            );

            $name = $this->spreadsheetHelper->nullIfEmpty(
                $row['name'] ?? null
            );

            if (! $erpPartnerCode || ! $name) {
                $skipped++;

                continue;
            }

            $supplier = Supplier::query()->firstOrNew([
                'erp_partner_code' => $erpPartnerCode,
            ]);

            $isNew = ! $supplier->exists;

            $supplier->name = $name;

            $shortName = $this->spreadsheetHelper->nullIfEmpty(
                $row['short_name'] ?? null
            );

            $addrId = $this->spreadsheetHelper->nullIfEmpty(
                $row['addrid'] ?? null
            );

            if ($addrId !== null) {
                $supplier->addrid = $addrId;
            }

            if ($shortName !== null) {
                $supplier->short_name = $shortName;
            }

            $activeRaw = $row['active'] ?? null;
            $activeIsEmpty =
                $activeRaw === null
                || trim((string) $activeRaw) === '';

            if ($isNew) {
                $supplier->active = $activeIsEmpty
                    ? true
                    : $this->spreadsheetHelper->boolValue(
                        $activeRaw,
                        true
                    );
            } elseif (! $activeIsEmpty) {
                $supplier->active =
                    $this->spreadsheetHelper->boolValue(
                        $activeRaw,
                        $supplier->active
                    );
            }

            if ($isNew) {
                $supplier->save();
                $created++;

                continue;
            }

            if (! $supplier->isDirty()) {
                $unchanged++;

                continue;
            }

            $supplier->save();
            $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ];
    }
}