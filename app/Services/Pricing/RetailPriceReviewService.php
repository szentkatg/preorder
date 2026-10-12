<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetailPriceReviewService
{
    public function setManualPrice(PricingCalculationRow $row, mixed $price): PricingCalculationRow
    {
        if ($price !== null && (! is_numeric($price) || (float) $price <= 0 || (float) $price > 99999999999.9999)) {
            throw new DomainException('A manuális ár pozitív szám legyen.');
        }

        return $this->change($row, function (PricingCalculationRow $stored) use ($price): void {
            $stored->manual_price = $price;
            $stored->retained_price = null;
            $stored->requires_review = false;
            $stored->status = PricingCalculationRow::STATUS_CALCULATED;
            $stored->reviewed_by = $stored->approved_by = null;
            $stored->reviewed_at = $stored->approved_at = null;
            $snapshot = $stored->calculation_snapshot ?? [];
            unset($snapshot['approval']);
            $stored->calculation_snapshot = $snapshot;
        });
    }

    public function approveCurrentPrice(PricingCalculationRow $row, string $status = PricingCalculationRow::STATUS_APPROVED): PricingCalculationRow
    {
        if (! in_array($status, [PricingCalculationRow::STATUS_REVIEWED, PricingCalculationRow::STATUS_APPROVED,
            PricingCalculationRow::STATUS_APPROVED_ROUND_2, PricingCalculationRow::STATUS_APPROVED_ROUND_3], true)) {
            throw new DomainException('Érvénytelen jóváhagyási státusz.');
        }

        return $this->change($row, function (PricingCalculationRow $stored) use ($status): void {
            if ($stored->final_price === null || (float) $stored->final_price <= 0) {
                throw new DomainException('Nincs jóváhagyható végleges ár.');
            }
            $stored->status = ($status === PricingCalculationRow::STATUS_APPROVED
                && ($stored->manual_price !== null || $stored->retained_price !== null))
                ? PricingCalculationRow::STATUS_MODIFIED_APPROVED : $status;
            $stored->requires_review = false;
            $stored->reviewed_by = $stored->approved_by = auth()->id();
            $stored->reviewed_at = $stored->approved_at = now();
            if ($status === PricingCalculationRow::STATUS_REVIEWED) {
                $stored->approved_by = $stored->approved_at = null;
            }
            $this->saveApprovalInputs($stored, (float) $stored->final_price);
        });
    }

    public function acceptCalculatedPrice(PricingCalculationRow $row): PricingCalculationRow
    {
        return $this->change($row, function (PricingCalculationRow $stored): void {
            if ($stored->calculated_price === null || (float) $stored->calculated_price <= 0) {
                throw new DomainException('Nincs elfogadható kalkulált ár.');
            }
            $stored->manual_price = $stored->retained_price = null;
            $stored->requires_review = false;
            $stored->status = PricingCalculationRow::STATUS_APPROVED;
            $stored->reviewed_by = $stored->approved_by = auth()->id();
            $stored->reviewed_at = $stored->approved_at = now();
            $this->saveApprovalInputs($stored, (float) $stored->calculated_price);
        });
    }

    private function saveApprovalInputs(PricingCalculationRow $row, float $price): void
    {
        $snapshot = $row->calculation_snapshot ?? [];
        $row->calculation_snapshot = array_replace($snapshot, ['approval' => [
            'price' => $price, 'user_id' => auth()->id(), 'approved_at' => now()->toISOString(),
            'source_cost_row_id' => $snapshot['source_cost_row_id'] ?? null,
            'inputs' => $snapshot['inputs'] ?? [], 'rounding' => $snapshot['rounding'] ?? [],
            'retail_parameter' => $snapshot['retail_parameter'] ?? [],
            'calculated_price' => (float) $row->calculated_price,
            'status' => $row->status,
        ]]);
    }

    private function change(PricingCalculationRow $row, Closure $change): PricingCalculationRow
    {
        Gate::authorize('update', $row);

        return DB::transaction(function () use ($row, $change): PricingCalculationRow {
            $stored = PricingCalculationRow::query()->lockForUpdate()->findOrFail($row->id);
            Gate::authorize('update', $stored);

            if ($stored->price_type !== PricingCalculationRow::TYPE_RETAIL || $stored->currency?->code !== 'HUF'
                || ! $stored->pricingProject?->active || $stored->pricingProject->status === PricingProject::STATUS_CLOSED) {
                throw new DomainException('Csak aktív árprojekt HUF kisker ára módosítható.');
            }

            $change($stored);
            $stored->save();

            return $stored;
        });
    }
}
