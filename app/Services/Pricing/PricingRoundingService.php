<?php

namespace App\Services\Pricing;

use App\Models\PricingRoundingRule;

class PricingRoundingService
{
    public function apply(float $value, ?PricingRoundingRule $rule): float
    {
        if (! $rule) {
            return $value;
        }

        $rounded = $value;

        if ($rule->round_to !== null && (float) $rule->round_to > 0) {
            $roundTo = (float) $rule->round_to;

            $rounded = match ($rule->mode) {
                PricingRoundingRule::MODE_UP => ceil($value / $roundTo) * $roundTo,
                PricingRoundingRule::MODE_DOWN => floor($value / $roundTo) * $roundTo,
                default => round($value / $roundTo) * $roundTo,
            };
        } elseif ($rule->decimal_places !== null) {
            $rounded = match ($rule->mode) {
                PricingRoundingRule::MODE_UP => ceil($value * (10 ** $rule->decimal_places)) / (10 ** $rule->decimal_places),
                PricingRoundingRule::MODE_DOWN => floor($value * (10 ** $rule->decimal_places)) / (10 ** $rule->decimal_places),
                default => round($value, $rule->decimal_places),
            };
        }

        return $rounded + (float) $rule->adjustment;
    }
}
