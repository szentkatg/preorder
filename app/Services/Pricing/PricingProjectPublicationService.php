<?php

namespace App\Services\Pricing;

use App\Models\PriceListItem;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\PricingProjectPublication;
use App\Models\ProductPurchasePrice;
use App\Models\Season;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PricingProjectPublicationService
{
    public function publish(PricingProject $project): PricingProjectPublication
    {
        Gate::authorize('publish', $project);

        return DB::transaction(function () use ($project): PricingProjectPublication {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            Gate::authorize('publish', $project);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Az árprojekt már lezárt. Előbb jogosultsággal vissza kell nyitni.');
            }
            Season::whereKey($project->season_id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->prepare($project);
            foreach ($snapshot['prices'] as &$price) {
                $old = PriceListItem::where('price_list_id', $price['price_list_id'])->where('season_id', $project->season_id)
                    ->where('product_id', $price['product_id'])->lockForUpdate()->first();
                $price['previous_price'] = $old?->net_price;
                PriceListItem::updateOrCreate(['price_list_id' => $price['price_list_id'], 'season_id' => $project->season_id, 'product_id' => $price['product_id']], ['net_price' => $price['price']]);
            }
            unset($price);
            $publication = $project->publications()->create(['published_by' => auth()->id(), 'published_at' => now(), 'snapshot' => $snapshot]);
            $project->update(['status' => PricingProject::STATUS_CLOSED, 'closed_at' => now(), 'closed_by' => auth()->id()]);

            return $publication;
        });
    }

    public function reopen(PricingProject $project): void
    {
        Gate::authorize('reopen', $project);
        DB::transaction(function () use ($project): void {
            $project = PricingProject::lockForUpdate()->findOrFail($project->id);
            Gate::authorize('reopen', $project);
            if ($project->status !== PricingProject::STATUS_CLOSED) {
                throw new DomainException('Csak lezárt árprojekt nyitható vissza.');
            }
            $project->publications()->latest('id')->first()?->update(['reopened_at' => now(), 'reopened_by' => auth()->id()]);
            $project->update(['status' => PricingProject::STATUS_ACTIVE, 'closed_at' => null, 'closed_by' => null]);
        });
    }

    /** Validate every published row first; no writes or implicit approvals. */
    public function prepare(PricingProject $project): array
    {
        $rows = $project->calculationRows()->whereHas('product', fn ($q) => $q->where('active', true))
            ->with(['product', 'priceList.currency', 'currency', 'pricingProject'])->orderBy('id')->get();
        $snapshot = ['season_id' => $project->season_id, 'season_code' => $project->season?->code,
            'products' => [], 'lists' => [], 'prices' => []];
        $groups = [];
        $memberIds = $project->products()->pluck('products.id')->all();
        foreach ($rows as $row) {
            if (! in_array($row->product_id, $memberIds)) {
                throw new DomainException($row->product->model_code.': a termék nincs felvéve az árprojektbe.');
            }
            if ($row->product->season_id != $project->season_id) {
                throw new DomainException($row->product->model_code.': eltérő szezonú termék az árprojektben.');
            }
            if ($row->price_type === 'cost') {
                continue;
            }
            $list = $row->priceList;
            if ($list && ! $list->active) {
                continue;
            }
            if (! $list || ! $list->currency?->active || $list->type !== $row->price_type || $list->currency_id != $row->currency_id) {
                throw new DomainException('Hiányzó/inaktív vagy hibás árlista a kalkulációs soron: #'.$row->id);
            }
            if (! in_array($row->status, ['approved', 'modified_approved', 'approved_round_2', 'approved_round_3'], true)
                || $row->requires_review || (float) $row->final_price <= 0) {
                throw new DomainException($row->product->model_code.' / '.$list->code.': csak pozitív, jóváhagyott és felülvizsgálatmentes ár publikálható.');
            }
            match ($row->price_type) {
                'retail' => app(RetailCalculationDependencyService::class)->assertCalculationCurrent($row),
                'wholesale' => app(WholesaleCalculator::class)->assertCurrent($row),
                'distributor' => app(DistributorCalculator::class)->assertCurrent($row),
                'outlet' => app(OutletCalculator::class)->assertCurrent($row),
                default => throw new DomainException('Nem támogatott publikálási ártípus: '.$row->price_type),
            };
            if ($row->price_type === 'outlet' && ! $row->outletDiscountIsSufficient()) {
                throw new DomainException($list->code.': az outlet kedvezmény a minimum alatt van.');
            }
            $key = $row->product_id.':'.$list->id;
            if (isset($groups[$key]) && ! BigDecimal::of($groups[$key]['price'])->isEqualTo(BigDecimal::of($row->final_price))) {
                throw new DomainException($row->product->model_code.' / '.$list->code.': a színekhez eltérő ár tartozik; a végleges árlista modellszintű.');
            }
            $groups[$key] ??= ['product_id' => $row->product_id, 'price_list_id' => $list->id, 'price' => $row->final_price, 'calculation_row_ids' => []];
            $groups[$key]['calculation_row_ids'][] = $row->id;
            $snapshot['lists'][$list->id] = $list->only(['id', 'code', 'type', 'currency_id']) + ['currency' => $list->currency->code];
            $snapshot['products'][$row->product_id] = $row->product->only(['id', 'model_code', 'name_hu', 'name_en']);
        }
        if ($groups === []) {
            throw new DomainException('Nincs publikálható ár az árprojektben.');
        }
        foreach ($snapshot['products'] as $id => &$product) {
            $costs = $rows->filter(fn ($row) => $row->product_id == $id && $row->price_type === 'cost' && $row->price_list_key == 0 && $row->currency?->code === 'HUF');
            $values = $costs->map(fn ($row) => (string) BigDecimal::of($row->final_price ?? 0)->stripTrailingZeros())->unique();
            if ($values->count() !== 1 || (float) $values->first() <= 0 || $costs->contains(fn ($row) => $row->requires_review)) {
                throw new DomainException($product['model_code'].': hiányzó, felülvizsgálandó vagy színenként eltérő bekerülési érték.');
            }
            foreach ($costs as $cost) {
                $this->assertCostCurrent($project, $cost);
            }
            $product['cost'] = $values->first();
        }
        unset($product);
        $snapshot['prices'] = array_values($groups);

        return $snapshot;
    }

    private function assertCostCurrent(PricingProject $project, PricingCalculationRow $row): void
    {
        // Legacy/manual cost rows may not carry a purchase-price source. Calculated
        // costs do, and must still match the current cheapest active candidate.
        if (! $row->product_purchase_price_id && ! isset($row->calculation_snapshot['selected_purchase_price'])) {
            return;
        }
        $prices = ProductPurchasePrice::where('product_id', $row->product_id)->where('color_key', $row->color_key)
            ->where('active', true)->with(['product', 'currency', 'supplier'])->get();
        $calculations = [];
        foreach ($prices as $price) {
            try {
                $calculation = app(LandedCostCalculator::class)->calculate($project, $price);
                if ($price->currency?->active && is_finite($calculation['landed_cost_huf']) && $calculation['landed_cost_huf'] > 0) {
                    $calculations[$price->getKey()] = $calculation;
                }
            } catch (\RuntimeException) {
                // As in CostCalculationService, an invalid alternative is not a candidate.
            }
        }
        $current = $calculations[$row->product_purchase_price_id] ?? null;
        $selected = $prices->firstWhere('product_purchase_price_id', $row->product_purchase_price_id);
        $valid = $current && $selected && $selected->supplier_id == $row->supplier_id && $selected->currency_id == $row->purchase_currency_id;
        if ($valid) {
            $minimum = min(array_column($calculations, 'landed_cost_huf'));
            $valid = BigDecimal::of($current['landed_cost_huf'])->toScale(4, RoundingMode::HALF_UP)
                ->isEqualTo(BigDecimal::of($minimum)->toScale(4, RoundingMode::HALF_UP));
            foreach (['purchase_price', 'exchange_rate', 'shipping_cost_percent', 'customs_percent', 'calculated_price'] as $field) {
                $expected = $field === 'calculated_price' ? $current['landed_cost_huf'] : $current[$field];
                $scale = $field === 'exchange_rate' ? 6 : 4;
                $valid = $valid && $row->{$field} !== null && BigDecimal::of($row->{$field})->toScale($scale, RoundingMode::HALF_UP)
                    ->isEqualTo(BigDecimal::of($expected)->toScale($scale, RoundingMode::HALF_UP));
            }
        }
        if (! $valid) {
            throw new DomainException($row->product?->model_code.': a bekerülési kalkuláció beszerzési ára, árfolyama vagy paramétere megváltozott. Előbb számítsd újra a bekerülési értéket és a ráépülő árakat.');
        }
    }
}
