<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class OutletCalculator extends WholesaleCalculator
{
    public function calculate(PricingProject $project, PricingCalculationRow $source, PriceList $list): array
    {
        $source->loadMissing(['product', 'supplier', 'priceList', 'currency']);
        $list->loadMissing(['currency', 'sourceRetailPriceList']);
        if (! $list->active || ! $list->currency?->active || $list->type !== 'outlet' || ! $list->sourceRetailPriceList?->active
            || $list->sourceRetailPriceList->type !== 'retail' || $list->sourceRetailPriceList->currency_id != $list->currency_id
            || $source->price_type !== 'retail' || $source->price_list_id != $list->source_retail_price_list_id
            || $source->currency_id != $list->currency_id || $source->pricing_project_id != $project->id
            || ! $source->product?->active || $source->product->season_id != $project->season_id
            || $source->requires_review || (float) $source->final_price <= 0) {
            throw new DomainException('Az outlethez aktuális, felülvizsgálatmentes, azonos pénznemű normál kisker forrás szükséges.');
        }
        app(RetailCalculationDependencyService::class)->assertCalculationCurrent($source);
        $parameter = $this->parameters->resolve($project->id, PricingParameter::TYPE_OUTLET_DISCOUNT,
            $source->product, $source->supplier?->country_code, $list->id, $list->currency_id, requireUnique: true);
        if (! $parameter || ($parameter->currency_id === null && $parameter->price_list_id === null)
            || (float) $parameter->value < 0 || (float) $parameter->value >= 100) {
            throw new DomainException('Pénznemhez vagy outlet árlistához rendelt Outlet kedvezmény % szükséges, legalább 0 és 100-nál kisebb értékkel.');
        }
        $rule = $this->rounding->resolve($project->id, 'outlet', $list->id, $list->currency_id);
        if (! $rule || ($rule->price_list_id === null && $rule->currency_id === null)
            || ! in_array($rule->mode, [PricingRoundingRule::MODE_NEAREST, PricingRoundingRule::MODE_UP, PricingRoundingRule::MODE_DOWN], true)
            || ((float) $rule->round_to <= 0 && ($rule->decimal_places === null || $rule->decimal_places < 0 || $rule->decimal_places > 4))) {
            throw new DomainException('Pénznem- vagy árlistaspecifikus outlet kerekítési szabály szükséges.');
        }
        $factor = BigDecimal::one()->minus(BigDecimal::of($parameter->value)->dividedBy(100, 8, RoundingMode::HALF_UP));
        $unrounded = BigDecimal::of($source->final_price)->multipliedBy($factor)->toFloat();
        $price = $this->rounding->apply($unrounded, $rule);
        if (! is_finite($price) || $price <= 0 || $price > 99999999999.9999 || $unrounded > 99999999999.9999) {
            throw new DomainException('Az outlet kalkuláció nem pozitív vagy túl nagy árat eredményezett.');
        }

        return ['source_retail_row_id' => $source->id, 'source_retail_price' => (float) $source->final_price,
            'base_price' => (float) $source->final_price, 'outlet_discount_percent' => (float) $parameter->value,
            'multiplier' => $factor->toFloat(), 'unrounded_price' => $unrounded, 'calculated_price' => $price,
            'pricing_rounding_rule_id' => $rule->id, 'snapshot' => [
                'calculated_at' => now()->toISOString(), 'currency' => $list->currency?->code,
                'formula' => 'Bruttó kisker ár × (1 − outlet kedvezmény% / 100) → outlet kerekítés = bruttó outlet ár',
                'source_retail_row_id' => $source->id,
                'source_retail_price_list' => ['id' => $source->price_list_id, 'code' => $source->priceList->code],
                'price_list' => ['id' => $list->id, 'code' => $list->code],
                'inputs' => ['base_price' => (float) $source->final_price, 'multiplier' => $factor->toFloat(),
                    'outlet_discount_percent' => (float) $parameter->value, 'unrounded_price' => $unrounded],
                'outlet_parameter' => $parameter->only(['id', 'scope_type', 'value', 'currency_id', 'price_list_id', 'product_id', 'item_main_group_id']),
                'rounding' => $rule->only(['id', 'name', 'round_to', 'decimal_places', 'mode', 'adjustment']), 'calculated_price' => $price,
            ]];
    }

    public function inputsChanged(PricingCalculationRow $row, array $calculation, bool $includeApproval = true): bool
    {
        return $row->outlet_discount_percent === null || BigDecimal::of($row->outlet_discount_percent)
            ->compareTo(BigDecimal::of($calculation['outlet_discount_percent'])) !== 0
            || parent::inputsChanged($row, $calculation, $includeApproval);
    }

    public function assertCurrent(PricingCalculationRow $row): void
    {
        $source = $row->sourceRetailRow;
        if (! $source || ! $row->priceList || $row->price_type !== 'outlet'
            || $source->product_id != $row->product_id || $source->color_key != $row->color_key
            || $row->priceList->currency_id != $row->currency_id
            || $this->inputsChanged($row, $this->calculate($row->pricingProject, $source, $row->priceList), includeApproval: false)) {
            throw new DomainException('Az outlet kalkuláció forrása hiányzik vagy elavult. Outlet újraszámítás szükséges.');
        }
    }
}
