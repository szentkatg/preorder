<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;

/** Internal writer: callers hold the project lock inside a transaction and authorize all writes. */
class DerivedPriceRowWriter
{
    public function save(PricingProject $project, PricingCalculationRow $source, PriceList $list,
        array $calculation, WholesaleCalculator $calculator, string $runId): array
    {
        $row = PricingCalculationRow::query()->where('pricing_project_id', $project->id)->where('product_id', $source->product_id)
            ->where('color_key', $source->color_key)->where('price_list_key', $list->id)->where('price_type', $list->type)
            ->where('currency_id', $list->currency_id)->lockForUpdate()->first();
        $created = ! $row;
        $row ??= new PricingCalculationRow(['pricing_project_id' => $project->id, 'product_id' => $source->product_id,
            'color_id' => $source->color_id, 'price_list_id' => $list->id, 'price_type' => $list->type,
            'currency_id' => $list->currency_id, 'status' => PricingCalculationRow::STATUS_CALCULATED]);
        $previous = $row->calculation_snapshot ?? [];
        $protected = $row->manual_price !== null || $row->retained_price !== null || $row->status !== PricingCalculationRow::STATUS_CALCULATED;
        if (! $created && $protected && ($row->requires_review || $calculator->inputsChanged($row, $calculation))) {
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
        $row->calculation_snapshot = $calculation['snapshot'] + ['run_id' => $runId];
        if (isset($previous['approval'])) {
            $row->calculation_snapshot += ['approval' => $previous['approval']];
        }
        $row->save();

        return ['created' => $created, 'requires_review' => $row->requires_review];
    }
}
