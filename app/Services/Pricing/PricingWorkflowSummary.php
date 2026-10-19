<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Illuminate\Support\Facades\Gate;

class PricingWorkflowSummary
{
    public function forProject(PricingProject $project): array
    {
        Gate::authorize('view', $project);
        Gate::authorize('viewAny', PricingCalculationRow::class);
        $empty = ['total' => 0, 'approved' => 0, 'pending' => 0,
            'outlet_total' => 0, 'outlet_approved' => 0, 'outlet_pending' => 0];
        $summary = array_fill_keys(['cost', 'huf', 'foreign', 'wholesale', 'distributor', 'overview'], $empty);
        // No calculation snapshots, source chains or table rendering in this read-only summary.
        $rows = $project->calculationRows()->select(['id', 'product_id', 'color_key', 'price_type', 'price_list_key', 'currency_id',
            'status', 'requires_review', 'final_price', 'base_price', 'outlet_discount_percent'])
            ->with(['currency:id,code', 'product:id,active'])->get();
        foreach ($rows as $row) {
            $step = match ($row->price_type) {
                PricingCalculationRow::TYPE_COST => 'cost',
                PricingCalculationRow::TYPE_RETAIL, PricingCalculationRow::TYPE_OUTLET => $row->currency?->code === 'HUF' ? 'huf' : 'foreign',
                PricingCalculationRow::TYPE_WHOLESALE => 'wholesale',
                PricingCalculationRow::TYPE_DISTRIBUTOR => 'distributor',
                default => null,
            };
            if ($step === null) {
                continue;
            }
            $state = $row->needsPricingAttention() ? 'pending' : 'approved';
            $summary[$step]['total']++;
            $summary[$step][$state]++;
            if ($row->price_type === PricingCalculationRow::TYPE_OUTLET) {
                $summary[$step]['outlet_total']++;
                $summary[$step]['outlet_'.$state]++;
            }
        }
        // The final overview has one row per model/color, not per price list.
        foreach ($rows->groupBy(fn ($row) => $row->product_id.':'.$row->color_key) as $group) {
            if (! $group->contains(fn ($row) => $row->price_type === PricingCalculationRow::TYPE_COST && (int) $row->price_list_key === 0 && $row->product?->active)) {
                continue;
            }
            $summary['overview']['total']++;
            $state = $group->contains(fn ($row) => $row->needsPricingAttention()) ? 'pending' : 'approved';
            $summary['overview'][$state]++;
        }

        return $summary;
    }
}
