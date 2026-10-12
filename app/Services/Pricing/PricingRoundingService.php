<?php

namespace App\Services\Pricing;

use App\Models\PricingRoundingRule;
use DomainException;

class PricingRoundingService
{
    public function resolve(int $projectId, string $priceType, int $priceListId, int $currencyId): ?PricingRoundingRule
    {
        $rules = PricingRoundingRule::query()
            ->where('pricing_project_id', $projectId)
            ->where('price_type', $priceType)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('price_list_id')->orWhere('price_list_id', $priceListId))
            ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $currencyId))
            ->get()
            ->sortByDesc(fn (PricingRoundingRule $rule): int => ($rule->price_list_id !== null ? 2 : 0) + ($rule->currency_id !== null ? 1 : 0)
            )->values();

        if ($rules->count() > 1) {
            $weight = fn (PricingRoundingRule $rule): int => ($rule->price_list_id !== null ? 2 : 0) + ($rule->currency_id !== null ? 1 : 0);

            if ($weight($rules[0]) === $weight($rules[1])) {
                throw new DomainException('Több azonos szintű aktív kerekítési szabály van. Csak egy maradjon aktív.');
            }
        }

        return $rules->first();
    }

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
