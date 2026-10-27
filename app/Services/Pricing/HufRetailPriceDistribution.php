<?php

namespace App\Services\Pricing;

use App\Models\Color;
use App\Models\HufRetailPriceDistributionRow;
use App\Models\PricingCalculationRow;
use Illuminate\Database\Eloquent\Builder;

class HufRetailPriceDistribution
{
    public function query(?int $pricingProjectId): Builder
    {
        // A model may have multiple color-level calculation rows at the same price.
        // Deduplicate it before joining/counting its active colors.
        $models = PricingCalculationRow::query()->where('price_type', 'retail')
            ->where('pricing_project_id', $pricingProjectId)
            ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
            ->where('final_price', '>', 0)
            ->select(['pricing_project_id', 'price_list_id', 'product_id', 'final_price'])
            ->selectRaw('MIN(id) AS id')
            ->groupBy('pricing_project_id', 'price_list_id', 'product_id', 'final_price');
        $colors = Color::query()->where('active', true)->select('product_id')
            ->selectRaw('COUNT(*) AS active_color_count')->groupBy('product_id');

        $groups = HufRetailPriceDistributionRow::query()->fromSub($models, 'pricing_calculation_rows')
            ->join('products', 'products.id', '=', 'pricing_calculation_rows.product_id')
            ->leftJoinSub($colors, 'active_colors', 'active_colors.product_id', '=', 'pricing_calculation_rows.product_id')
            ->select(['pricing_calculation_rows.pricing_project_id', 'pricing_calculation_rows.price_list_id',
                'products.item_main_group_id', 'pricing_calculation_rows.final_price'])
            ->selectRaw('MIN(pricing_calculation_rows.id) AS id, COUNT(*) AS model_count, SUM(COALESCE(active_colors.active_color_count, 0)) AS color_count')
            ->groupBy('pricing_calculation_rows.pricing_project_id', 'pricing_calculation_rows.price_list_id',
                'products.item_main_group_id', 'pricing_calculation_rows.final_price');

        // An outer query also makes count-column search/filtering valid SQL (no aggregate in WHERE).
        return HufRetailPriceDistributionRow::query()->fromSub($groups->toBase(), 'pricing_calculation_rows')
            ->with(['pricingProject', 'priceList', 'itemMainGroup']);
    }
}
