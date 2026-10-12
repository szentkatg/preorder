<?php

namespace App\Services\Pricing;

use App\Models\Currency;
use App\Models\PriceList;
use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HufRetailCalculationService
{
    public function __construct(private readonly HufRetailCalculator $calculator) {}

    public function recalculate(PricingProject $project, ?PriceList $priceList = null): array
    {
        if (! $project->active || $project->status === PricingProject::STATUS_CLOSED) {
            throw new DomainException('Csak aktív, nem lezárt árprojekt számítható újra.');
        }

        $huf = Currency::query()->where('code', 'HUF')->first();

        if (! $huf) {
            throw new DomainException('Hiányzó HUF pénznem.');
        }

        $lists = PriceList::query()->where('active', true)->where('type', 'retail')
            ->where('currency_id', $huf->id)
            ->where(fn ($query) => $query->whereNull('season_id')->orWhere('season_id', $project->season_id))
            ->when($priceList, fn ($query) => $query->whereKey($priceList->id))
            ->with('currency')->get();

        if ($lists->isEmpty()) {
            throw new DomainException('Nincs a szezonhoz tartozó aktív HUF kisker árlista.');
        }

        $result = ['run_id' => (string) Str::uuid(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'requires_review' => 0, 'errors' => []];

        DB::transaction(function () use ($project, $huf, $lists, &$result): void {
            // Serialize calculations for the same project, including concurrent requests.
            $project = PricingProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (! $project->active || $project->status === PricingProject::STATUS_CLOSED) {
                throw new DomainException('Az árprojekt időközben inaktív vagy lezárt lett.');
            }
            $costs = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                ->where('price_type', PricingCalculationRow::TYPE_COST)->where('currency_id', $huf->id)
                ->where('price_list_key', 0)
                ->whereHas('product', fn ($query) => $query->where('active', true)->where('season_id', $project->season_id))
                ->with(['product.itemMainGroup', 'supplier', 'currency'])->lockForUpdate()->get();

            if ($costs->isEmpty()) {
                throw new DomainException('Előbb számítsd ki és mentsd el a szezon HUF bekerülési értékeit.');
            }

            foreach ($lists as $list) {
                foreach ($costs as $cost) {
                    try {
                        $calculation = $this->calculator->calculate($project, $cost, $list);
                    } catch (DomainException $exception) {
                        $message = $list->code.' / '.$cost->product->model_code.': '.$exception->getMessage();
                        $result['errors'][] = $message;
                        $result['skipped']++;
                        PricingCalculationError::query()->create([
                            'run_id' => $result['run_id'], 'pricing_project_id' => $project->id,
                            'price_type' => PricingCalculationRow::TYPE_RETAIL, 'product_id' => $cost->product_id,
                            'color_id' => $cost->color_id, 'supplier_id' => $cost->supplier_id,
                            'severity' => PricingCalculationError::SEVERITY_ERROR, 'message' => $message,
                            'context' => ['price_list_id' => $list->id, 'price_list_code' => $list->code, 'source_cost_row_id' => $cost->id],
                        ]);

                        continue;
                    }

                    $row = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                        ->where('product_id', $cost->product_id)->where('color_key', $cost->color_key)
                        ->where('price_list_key', $list->id)->where('price_type', PricingCalculationRow::TYPE_RETAIL)
                        ->where('currency_id', $huf->id)->lockForUpdate()->first();
                    $created = ! $row;
                    $row ??= new PricingCalculationRow([
                        'pricing_project_id' => $project->id, 'product_id' => $cost->product_id,
                        'color_id' => $cost->color_id, 'price_list_id' => $list->id,
                        'price_type' => PricingCalculationRow::TYPE_RETAIL, 'currency_id' => $huf->id,
                        'status' => PricingCalculationRow::STATUS_CALCULATED,
                    ]);
                    $previousSnapshot = $row->calculation_snapshot ?? [];

                    if (! $created && $row->calculated_price !== null
                        && BigDecimal::of($row->calculated_price)->compareTo(
                            BigDecimal::of($calculation['calculated_price'])->toScale(4, RoundingMode::HALF_UP)
                        ) !== 0
                        && ($row->manual_price !== null || $row->status !== PricingCalculationRow::STATUS_CALCULATED)) {
                        if ($row->manual_price === null && $row->retained_price === null) {
                            $row->retained_price = $row->final_price;
                        }
                        $row->requires_review = true;
                    }

                    $row->fill(collect($cost->getAttributes())->only([
                        'product_purchase_price_id', 'supplier_id', 'purchase_currency_id', 'purchase_price',
                        'exchange_rate', 'shipping_cost_percent', 'customs_percent', 'candidate_count',
                    ])->all());
                    $row->fill(collect($calculation)->except('snapshot')->all());
                    $row->calculation_snapshot = $calculation['snapshot'] + ['run_id' => $result['run_id']];
                    if (isset($previousSnapshot['approval'])) {
                        $row->calculation_snapshot = $row->calculation_snapshot + ['approval' => $previousSnapshot['approval']];
                    }
                    $row->save();
                    $result[$created ? 'created' : 'updated']++;
                    $result['requires_review'] += (int) $row->requires_review;
                }
            }
        });

        return $result;
    }
}
