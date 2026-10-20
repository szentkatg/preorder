<?php

namespace App\Services\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PricingAttentionQuery
{
    /** SQL counterpart of PricingCalculationRow::needsPricingAttention(), without loading snapshots. */
    public static function apply(Builder|QueryBuilder $query, string $state, string $alias = 'pricing_calculation_rows'): Builder|QueryBuilder
    {
        $pending = "(COALESCE({$alias}.requires_review, 0) <> 0 OR COALESCE({$alias}.final_price, 0) <= 0"
            ." OR ({$alias}.price_type <> 'cost' AND (COALESCE({$alias}.status, '') NOT IN"
            ." ('approved', 'modified_approved', 'approved_round_2', 'approved_round_3')"
            ." OR ({$alias}.price_type = 'outlet' AND (COALESCE({$alias}.base_price, 0) <= 0"
            ." OR {$alias}.outlet_discount_percent IS NULL OR {$alias}.outlet_discount_percent < 0"
            ." OR {$alias}.outlet_discount_percent >= 100"
            ." OR {$alias}.final_price * 100 > {$alias}.base_price * (100 - {$alias}.outlet_discount_percent))))))";

        return $query->whereRaw($state === 'approved' ? 'NOT '.$pending : $pending);
    }
}
