<?php

namespace App\Services\Pricing;

use DomainException;

/** SQL projections keep comparison sorting, filtering and averages off the PHP row loop. */
class DistributorComparison
{
    public static function sql(string $column, ?int $listId, ?float $vat = null): string
    {
        if (! $listId || $listId < 1) {
            return 'NULL';
        }
        $field = match ($column) {
            'distributor_discount_percent' => 'd.distributor_discount_percent',
            'distributor_calculated_price' => 'd.calculated_price',
            'distributor_manual_price' => 'd.manual_price',
            'distributor_final_price', 'distributor_net_net_multiplier' => 'COALESCE(d.manual_price, d.retained_price, d.calculated_price)',
            'distributor_status' => 'd.status',
            'distributor_requires_review' => 'd.requires_review',
            default => throw new DomainException('Ismeretlen disztribútori összehasonlító oszlop.'),
        };
        $value = '(SELECT '.$field.' FROM pricing_calculation_rows AS d'
            .' WHERE d.source_wholesale_row_id = pricing_calculation_rows.id'
            .' AND d.pricing_project_id = pricing_calculation_rows.pricing_project_id'
            .' AND d.product_id = pricing_calculation_rows.product_id AND d.color_key = pricing_calculation_rows.color_key'
            .' AND d.currency_id = pricing_calculation_rows.currency_id'
            ." AND d.price_type = 'distributor' AND d.price_list_key = ".(int) $listId.')';
        if ($column !== 'distributor_net_net_multiplier') {
            return $value;
        }
        if ($vat === null || ! is_finite($vat) || $vat < 0 || $vat > 100) {
            return 'NULL';
        }

        return 'CASE WHEN pricing_calculation_rows.base_price > 0 AND '.$value.' > 0 THEN'
            .' 1.0 * pricing_calculation_rows.base_price / NULLIF('.$value.', 0) / '.json_encode(1 + $vat / 100, JSON_PRESERVE_ZERO_FRACTION).' ELSE NULL END';
    }
}
