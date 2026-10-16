<?php

namespace App\Services\Pricing;

use Illuminate\Database\Eloquent\Builder;

class PricingMultiplierAverage
{
    /** Sum of dividends / sum of divisors, using exactly the same valid rows for both. */
    public static function calculate(Builder $filteredQuery, string $numeratorSql, string $denominatorSql): ?float
    {
        // Project only the inputs, not the table's calculated display columns or pagination.
        // SQL expressions are supplied by application code, never by user input.
        $inputs = (clone $filteredQuery)->reorder()->select([])->selectRaw(
            '('.$numeratorSql.') AS pricing_numerator, ('.$denominatorSql.') AS pricing_denominator',
        )->toBase();
        $result = $filteredQuery->getConnection()->query()->fromSub($inputs, 'pricing_average_inputs')
            ->whereNotNull('pricing_numerator')->where('pricing_denominator', '>', 0)
            ->selectRaw('1.0 * SUM(pricing_numerator) / NULLIF(SUM(pricing_denominator), 0) AS multiplier')
            ->first();

        return $result?->multiplier === null ? null : (float) $result->multiplier;
    }
}
