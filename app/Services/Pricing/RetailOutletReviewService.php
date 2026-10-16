<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetailOutletReviewService
{
    /** Each retail/outlet group is atomic: a rejected outlet cannot leave its retail newly approved. */
    public function approve(PricingCalculationRow $source, array $enabledListIds, string $mode, string $status): int
    {
        if (! in_array($mode, ['outlet', 'all'], true)) {
            throw new DomainException('Érvénytelen outlet jóváhagyási mód.');
        }
        Gate::authorize('update', $source);

        return DB::transaction(function () use ($source, $enabledListIds, $mode, $status): int {
            PricingProject::whereKey($source->pricing_project_id)->lockForUpdate()->firstOrFail();
            $source = PricingCalculationRow::lockForUpdate()->findOrFail($source->id);
            Gate::authorize('update', $source);
            if ($source->price_type !== 'retail' || ! $source->pricingProject?->isOpenForPricing()) {
                throw new DomainException('Csak nyitott árprojekt normál kisker sorához tartozó árak hagyhatók jóvá.');
            }
            $listIds = PriceList::whereIn('id', $enabledListIds)->where('active', true)->where('type', 'outlet')
                ->where('source_retail_price_list_id', $source->price_list_id)->where('currency_id', $source->currency_id)->pluck('id');
            $rows = $source->outletRows()->whereIn('price_list_id', $listIds)->where('pricing_project_id', $source->pricing_project_id)
                ->where('product_id', $source->product_id)->where('color_key', $source->color_key)->where('currency_id', $source->currency_id)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== $listIds->count() || ($rows->isEmpty() && $mode === 'outlet')) {
                throw new DomainException('Hiányzik outlet kalkuláció. Előbb számítsd újra az outlet árakat.');
            }
            $approved = 0;
            if ($mode === 'all') {
                app(RetailPriceReviewService::class)->approveCurrentPrice($source, $status);
                $approved++;
            }
            foreach ($rows as $row) {
                app(OutletPriceReviewService::class)->approveCurrentPrice($row, $status);
                $approved++;
            }

            return $approved;
        });
    }

    public function approveRows(Collection $rows, array $listIds, string $mode, string $status): array
    {
        Gate::authorize('update', new PricingCalculationRow);
        $result = ['approved' => 0, 'skipped' => 0, 'reasons' => []];

        return DB::transaction(function () use ($rows, $listIds, $mode, $status, $result): array {
            foreach ($rows->unique('id')->sortBy([['pricing_project_id', 'asc'], ['id', 'asc']]) as $row) {
                try {
                    $result['approved'] += $this->approve($row, $listIds, $mode, $status);
                } catch (DomainException|AuthorizationException|ModelNotFoundException $exception) {
                    $result['skipped']++;
                    $reason = $exception instanceof AuthorizationException ? 'Nincs módosítási jogosultság.' : $exception->getMessage();
                    $result['reasons'][$reason] = ($result['reasons'][$reason] ?? 0) + 1;
                }
            }

            return $result;
        });
    }
}
