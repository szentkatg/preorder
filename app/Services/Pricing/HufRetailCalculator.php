<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use Brick\Math\BigDecimal;
use DomainException;

class HufRetailCalculator
{
    public function __construct(
        private readonly PricingParameterResolver $parameters,
        private readonly PricingRoundingService $rounding,
    ) {}

    public function calculate(PricingProject $project, PricingCalculationRow $cost, PriceList $priceList): array
    {
        $cost->loadMissing(['product', 'supplier', 'currency']);
        $priceList->loadMissing('currency');

        if ($cost->pricing_project_id != $project->id || $cost->price_type !== PricingCalculationRow::TYPE_COST
            || $cost->currency?->code !== 'HUF' || ! $cost->product
            || $cost->product->season_id != $project->season_id || ! $cost->product->active) {
            throw new DomainException('Az árprojekt aktív termékéhez tartozó HUF bekerülési érték szükséges.');
        }

        if (! $priceList->active || $priceList->type !== 'retail' || $priceList->currency?->code !== 'HUF'
            || ($priceList->season_id !== null && $priceList->season_id != $project->season_id)) {
            throw new DomainException('A szezonhoz tartozó aktív HUF kisker árlista szükséges.');
        }

        if ($cost->final_price === null || (float) $cost->final_price <= 0) {
            throw new DomainException('A végleges bekerülési érték hiányzik vagy nem pozitív.');
        }

        $parameter = $this->parameters->resolve(
            $project->id, PricingParameter::TYPE_RETAIL_MULTIPLIER, $cost->product,
            $cost->supplier?->country_code, $priceList->id, $priceList->currency_id,
        );

        if (! $parameter || (float) $parameter->value <= 0) {
            throw new DomainException('Hiányzó vagy nem pozitív Kisker szorzó paraméter.');
        }

        $rule = $this->rounding->resolve($project->id, PricingCalculationRow::TYPE_RETAIL, $priceList->id, $priceList->currency_id);

        if (! $rule) {
            throw new DomainException('Hiányzó aktív kisker kerekítési szabály (például: 1000 HUF, legközelebbi, -10 korrekció).');
        }

        if (! in_array($rule->mode, [PricingRoundingRule::MODE_NEAREST, PricingRoundingRule::MODE_UP, PricingRoundingRule::MODE_DOWN], true)
            || ((float) $rule->round_to <= 0 && ($rule->decimal_places === null || $rule->decimal_places < 0 || $rule->decimal_places > 4))) {
            throw new DomainException('A kerekítési szabályhoz pozitív egység vagy 0–4 tizedes, valamint érvényes kerekítési mód szükséges.');
        }

        $base = (float) $cost->final_price;
        $multiplier = (float) $parameter->value;
        $unrounded = BigDecimal::of($cost->final_price)->multipliedBy($parameter->value)->toFloat();
        $retail = $this->rounding->apply($unrounded, $rule);

        if (! is_finite($retail) || $retail <= 0 || $retail > 99999999999.9999) {
            throw new DomainException('A kerekítési szabály nem pozitív vagy túl nagy kisker árat eredményezett.');
        }

        return [
            'base_price' => $base,
            'multiplier' => $multiplier,
            'unrounded_price' => $unrounded,
            'calculated_price' => $retail,
            'pricing_rounding_rule_id' => $rule->id,
            'snapshot' => [
                'calculated_at' => now()->toISOString(),
                'formula' => 'HUF bekerülési érték × kisker szorzó → kerekítés → korrekció',
                'source_cost_row_id' => $cost->id,
                'source_cost_updated_at' => $cost->updated_at?->toISOString(),
                'source_cost_snapshot' => $cost->calculation_snapshot,
                'price_list' => ['id' => $priceList->id, 'code' => $priceList->code, 'name' => $priceList->name_hu],
                'inputs' => ['base_price' => $base, 'multiplier' => $multiplier, 'unrounded_price' => $unrounded],
                'retail_parameter' => [
                    'id' => $parameter->id, 'scope_type' => $parameter->scope_type,
                    'product_id' => $parameter->product_id, 'item_main_group_id' => $parameter->item_main_group_id,
                    'supplier_country_code' => $parameter->supplier_country_code,
                    'price_list_id' => $parameter->price_list_id, 'currency_id' => $parameter->currency_id,
                    'value' => $multiplier,
                ],
                'rounding' => [
                    'id' => $rule->id, 'name' => $rule->name, 'round_to' => $rule->round_to,
                    'decimal_places' => $rule->decimal_places, 'mode' => $rule->mode,
                    'adjustment' => (float) $rule->adjustment,
                    'rounded_before_adjustment' => $retail - (float) $rule->adjustment,
                ],
                'calculated_price' => $retail,
            ],
        ];
    }
}
