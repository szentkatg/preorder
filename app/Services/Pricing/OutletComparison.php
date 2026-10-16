<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

class OutletComparison
{
    public static function sql(string $column): string
    {
        if (! preg_match('/^outlet_(\d+)_(discount_percent|actual_discount|calculated_price|manual_price|final_price|requires_review|status)$/D', $column, $matches)) {
            throw new InvalidArgumentException('Invalid outlet column.');
        }
        $final = 'COALESCE(o.manual_price, o.retained_price, o.calculated_price)';
        $expression = match ($matches[2]) {
            'discount_percent' => 'o.outlet_discount_percent',
            'actual_discount' => 'CASE WHEN o.base_price > 0 THEN (1 - 1.0 * '.$final.' / o.base_price) * 100 ELSE NULL END',
            'final_price' => $final,
            default => 'o.'.$matches[2],
        };

        return '(SELECT '.$expression.' FROM pricing_calculation_rows o WHERE o.source_retail_row_id = pricing_calculation_rows.id AND o.price_type = \'outlet\' AND o.price_list_key = '.(int) $matches[1].' AND o.pricing_project_id = pricing_calculation_rows.pricing_project_id AND o.product_id = pricing_calculation_rows.product_id AND o.color_key = pricing_calculation_rows.color_key AND o.currency_id = pricing_calculation_rows.currency_id LIMIT 1)';
    }
}
