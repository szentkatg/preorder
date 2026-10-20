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

class ForeignRetailCalculationService
{
    public function __construct(
        private readonly ForeignRetailCalculator $calculator,
        private readonly RetailCalculationDependencyService $dependencies,
    ) {}

    public function recalculateAll(PricingProject $project, array $sourceSelections = []): array
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);

        return DB::transaction(function () use ($project, $sourceSelections): array {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Lezárt árprojekt nem számítható újra.');
            }
            // Resolve and validate every pair before the first price is changed.
            $pairs = app(ForeignRetailPriceListResolver::class)->resolveAll($sourceSelections);
            $result = ['run_id' => (string) Str::uuid(), 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'requires_review' => 0, 'price_lists' => count($pairs), 'pairs' => [], 'errors' => []];
            foreach ($pairs as $pair) {
                $run = $this->recalculate($project, $pair['source'], $pair['target'], $result['run_id']);
                foreach (['created', 'updated', 'skipped', 'requires_review'] as $key) {
                    $result[$key] += $run[$key];
                }
                $result['pairs'][] = $pair['source']->code.' → '.$pair['target']->code;
                $result['errors'] = [...$result['errors'], ...$run['errors']];
            }

            return $result;
        });
    }

    public function recalculate(PricingProject $project, PriceList $sourceList, PriceList $targetList, ?string $runId = null, ?int $productId = null): array
    {
        Gate::authorize('update', $project);
        Gate::authorize('create', PricingCalculationRow::class);
        Gate::authorize('update', new PricingCalculationRow);
        $sourceList->loadMissing('currency');
        $targetList->loadMissing('currency');
        if (! $project->isOpenForPricing() || ! $sourceList->active || $sourceList->type !== 'retail'
            || $sourceList->currency?->code !== 'HUF' || ! $targetList->active || $targetList->type !== 'retail'
            || ! in_array($targetList->currency?->code, ForeignRetailCalculator::CURRENCIES, true)) {
            throw new DomainException('Nem lezárt árprojekt, aktív HUF forrás- és devizás célárlista szükséges.');
        }
        $result = ['run_id' => $runId ?? (string) Str::uuid(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requires_review' => 0, 'errors' => []];

        return DB::transaction(function () use ($project, $sourceList, $targetList, $result, $productId): array {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Az árprojekt időközben lezárt lett.');
            }
            $sources = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
                ->where('price_type', 'retail')->where('price_list_id', $sourceList->id)->where('currency_id', $sourceList->currency_id)
                ->whereHas('product', fn ($query) => $query->forPricingProject($project->id)->where('active', true)->where('season_id', $project->season_id))
                ->with(['currency', 'product.itemMainGroup', 'supplier', 'priceList'])->lockForUpdate()->get();
            if ($sources->isEmpty()) {
                throw new DomainException('Előbb számítsd ki a kiválasztott HUF árlista kisker árait.');
            }
            foreach ($sources as $source) {
                try {
                    $calculation = $this->calculator->calculate($project, $source, $targetList);
                } catch (DomainException $exception) {
                    $failed = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                        ->where('product_id', $source->product_id)->where('color_key', $source->color_key)
                        ->where('price_list_key', $targetList->id)->where('price_type', 'retail')
                        ->where('currency_id', $targetList->currency_id);
                    $failedIds = (clone $failed)->pluck('id')->all();
                    $failed->update(['requires_review' => true]);
                    $this->dependencies->invalidateRetailDependents($failedIds);
                    $message = $targetList->code.' / '.$source->product->model_code.': '.$exception->getMessage();
                    $result['skipped']++;
                    $result['errors'][] = $message;
                    PricingCalculationError::create(['run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                        'price_type' => 'retail', 'product_id' => $source->product_id, 'color_id' => $source->color_id,
                        'supplier_id' => $source->supplier_id, 'severity' => PricingCalculationError::SEVERITY_ERROR,
                        'message' => $message, 'context' => ['source_retail_row_id' => $source->id, 'price_list_id' => $targetList->id]]);

                    continue;
                }
                $row = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                    ->where('product_id', $source->product_id)->where('color_key', $source->color_key)
                    ->where('price_list_key', $targetList->id)->where('price_type', 'retail')
                    ->where('currency_id', $targetList->currency_id)->lockForUpdate()->first();
                $created = ! $row;
                $row ??= new PricingCalculationRow(['pricing_project_id' => $project->id, 'product_id' => $source->product_id,
                    'color_id' => $source->color_id, 'price_list_id' => $targetList->id, 'price_type' => 'retail',
                    'currency_id' => $targetList->currency_id, 'status' => PricingCalculationRow::STATUS_CALCULATED]);
                $previousSnapshot = $row->calculation_snapshot ?? [];
                $protected = $row->manual_price !== null || $row->retained_price !== null || $row->status !== PricingCalculationRow::STATUS_CALCULATED;
                if (! $created && $protected && ($row->requires_review || $this->dependencies->foreignInputsChanged($row, $calculation)
                    || $this->dependencies->foreignApprovalInputsChanged($row, $calculation))) {
                    if ($row->manual_price === null && $row->retained_price === null) {
                        $row->retained_price = $row->final_price;
                    }
                    $row->requires_review = true;
                } elseif (! $protected) {
                    $row->requires_review = false;
                }
                $row->fill($source->only(['product_purchase_price_id', 'supplier_id', 'purchase_currency_id', 'purchase_price',
                    'exchange_rate', 'shipping_cost_percent', 'customs_percent', 'candidate_count']));
                $row->fill(collect($calculation)->except('snapshot')->all());
                $row->calculation_snapshot = $calculation['snapshot'] + ['run_id' => $result['run_id']];
                if (isset($previousSnapshot['approval'])) {
                    $row->calculation_snapshot += ['approval' => $previousSnapshot['approval']];
                }
                $row->save();
                $result[$created ? 'created' : 'updated']++;
                $result['requires_review'] += (int) $row->requires_review;
            }

            return $result;
        });
    }
}
