<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OutletPriceReviewService extends RetailPriceReviewService
{
    protected function assertEditableCalculation(PricingCalculationRow $stored): void
    {
        if ($stored->price_type !== 'outlet' || ! ($stored->pricingProject?->isOpenForPricing() ?? false)) {
            throw new DomainException('Csak nyitott árprojekt outlet ára módosítható.');
        }
        app(OutletCalculator::class)->assertCurrent($stored);
    }

    public function approveCurrentPrice(PricingCalculationRow $row, string $status = PricingCalculationRow::STATUS_APPROVED): PricingCalculationRow
    {
        return $this->approveWithMinimumCheck($row, false, $status);
    }

    public function acceptCalculatedPrice(PricingCalculationRow $row): PricingCalculationRow
    {
        return $this->approveWithMinimumCheck($row, true);
    }

    private function approveWithMinimumCheck(PricingCalculationRow $row, bool $reset, string $status = PricingCalculationRow::STATUS_APPROVED): PricingCalculationRow
    {
        Gate::authorize('update', $row);

        return DB::transaction(function () use ($row, $reset, $status): PricingCalculationRow {
            PricingProject::whereKey($row->pricing_project_id)->lockForUpdate()->firstOrFail();
            $stored = PricingCalculationRow::lockForUpdate()->findOrFail($row->id);
            Gate::authorize('update', $stored);
            $this->assertEditableCalculation($stored);
            if (! $stored->outletDiscountIsSufficient($reset ? $stored->calculated_price : $stored->final_price)) {
                throw new DomainException('Az outlet tényleges kedvezménye kisebb az előírt minimumnál. Csökkentsd az outlet árat vagy módosítsd a kerekítést.');
            }

            return $reset ? parent::acceptCalculatedPrice($stored) : parent::approveCurrentPrice($stored, $status);
        });
    }
}
