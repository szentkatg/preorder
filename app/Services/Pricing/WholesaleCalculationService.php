<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WholesaleCalculationService
{
    public function __construct(private readonly WholesaleCalculator $calculator) {}

    public function recalculate(PricingProject $project, ?string $currency = null, ?int $listId = null): array
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);
        if ($currency !== null && ! in_array($currency, WholesaleCalculator::CURRENCIES, true)) {
            throw new DomainException('Csak HUF és EUR nagyker ár számítható.');
        }

        return DB::transaction(function () use ($project, $currency, $listId): array {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt nem számítható újra.');
            }
            $lists = PriceList::query()->where('type', 'wholesale')->where('active', true)
                ->whereHas('currency', fn ($query) => $query->whereIn('code', $currency ? [$currency] : WholesaleCalculator::CURRENCIES))
                ->when($listId !== null, fn ($query) => $query->whereKey($listId))
                ->with(['currency', 'retailPriceList'])->orderBy('id')->get();
            if ($lists->isEmpty()) {
                throw new DomainException('Nincs megfelelő aktív HUF/EUR nagyker árlista.');
            }
            // Validate every price-list link before changing the first price.
            foreach ($lists as $list) {
                $retail = $list->retailPriceList;
                if (! $retail || ! $retail->active || $retail->type !== 'retail' || $retail->currency_id != $list->currency_id) {
                    throw new DomainException($list->code.': állíts be azonos pénznemű aktív Kapcsolt kisker árlistát az árlistatörzsben.');
                }
                if (! PricingCalculationRow::query()->where('pricing_project_id', $project->id)->where('price_type', 'retail')
                    ->where('price_list_id', $retail->id)->where('currency_id', $list->currency_id)
                    ->whereHas('product', fn ($query) => $query->where('active', true)->where('season_id', $project->season_id))->exists()) {
                    throw new DomainException($retail->code.': előbb számítsd ki a kisker árakat.');
                }
            }
            $result = ['run_id' => (string) Str::uuid(), 'price_lists' => $lists->count(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requires_review' => 0];
            foreach ($lists as $list) {
                $sources = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                    ->where('price_type', 'retail')->where('price_list_id', $list->retail_price_list_id)->where('currency_id', $list->currency_id)
                    ->whereHas('product', fn ($query) => $query->where('active', true)->where('season_id', $project->season_id))
                    ->with(['currency', 'supplier', 'product.itemMainGroup', 'priceList'])->lockForUpdate()->get();
                foreach ($sources as $source) {
                    $query = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                        ->where('product_id', $source->product_id)->where('color_key', $source->color_key)
                        ->where('price_list_key', $list->id)->where('price_type', 'wholesale')->where('currency_id', $list->currency_id);
                    try {
                        $calculation = $this->calculator->calculate($project, $source, $list);
                    } catch (DomainException $exception) {
                        $failedIds = (clone $query)->pluck('id');
                        $query->update(['requires_review' => true]);
                        PricingCalculationRow::where('price_type', 'distributor')->whereIn('source_wholesale_row_id', $failedIds)
                            ->update(['requires_review' => true]);
                        $result['skipped']++;
                        PricingCalculationError::create(['run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                            'price_type' => 'wholesale', 'product_id' => $source->product_id, 'color_id' => $source->color_id,
                            'supplier_id' => $source->supplier_id, 'severity' => PricingCalculationError::SEVERITY_ERROR,
                            'message' => $list->code.' / '.$source->product->model_code.': '.$exception->getMessage(),
                            'context' => ['price_list_id' => $list->id, 'source_retail_row_id' => $source->id]]);

                        continue;
                    }
                    $saved = app(DerivedPriceRowWriter::class)->save($project, $source, $list, $calculation, $this->calculator, $result['run_id']);
                    $result[$saved['created'] ? 'created' : 'updated']++;
                    $result['requires_review'] += (int) $saved['requires_review'];
                }
            }

            return $result;
        });
    }
}
