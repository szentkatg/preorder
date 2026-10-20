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

class DistributorCalculator extends WholesaleCalculator
{
    public function calculate(PricingProject $project, PricingCalculationRow $source, PriceList $list): array
    {
        $source->loadMissing(['product', 'currency', 'supplier', 'priceList']);
        $list->loadMissing(['currency', 'sourceWholesalePriceList']);
        if (! $list->active || $list->type !== 'distributor' || $list->currency?->code !== 'EUR'
            || $source->price_type !== PricingCalculationRow::TYPE_WHOLESALE || $source->currency?->code !== 'EUR'
            || $source->currency_id != $list->currency_id || $source->pricing_project_id != $project->id
            || $source->price_list_id != $list->source_wholesale_price_list_id || ! $list->sourceWholesalePriceList?->active
            || $list->sourceWholesalePriceList->type !== 'wholesale' || $list->sourceWholesalePriceList->currency_id != $list->currency_id
            || ! $source->product?->active || $source->product->season_id != $project->season_id
            || $source->requires_review || (float) $source->final_price <= 0) {
            throw new DomainException('Aktuális, felülvizsgálatmentes EUR nagyker forrás és helyesen kapcsolt aktív EUR disztribútori árlista szükséges.');
        }
        app(WholesaleCalculator::class)->assertCurrent($source);
        $parameter = $this->parameters->resolve($project->id, PricingParameter::TYPE_DISTRIBUTOR_DISCOUNT,
            $source->product, $source->supplier?->country_code, $list->id, $list->currency_id, requireUnique: true);
        if (! $parameter || (float) $parameter->value < 0 || (float) $parameter->value >= 100) {
            throw new DomainException('Disztribútor kedvezmény % paraméter szükséges: legalább 0 és 100-nál kisebb érték.');
        }
        $rule = $this->rounding->resolve($project->id, 'distributor', $list->id, $list->currency_id);
        if (! $rule || ($rule->price_list_id === null && $rule->currency_id === null)
            || ! in_array($rule->mode, [PricingRoundingRule::MODE_NEAREST, PricingRoundingRule::MODE_UP, PricingRoundingRule::MODE_DOWN], true)
            || ((float) $rule->round_to <= 0 && ($rule->decimal_places === null || $rule->decimal_places < 0 || $rule->decimal_places > 4))) {
            throw new DomainException('EUR- vagy árlistaspecifikus disztribútori kerekítési szabály szükséges.');
        }
        $factor = BigDecimal::one()->minus(BigDecimal::of($parameter->value)->dividedBy(100, 8, RoundingMode::HALF_UP));
        $unrounded = BigDecimal::of($source->final_price)->multipliedBy($factor)->toFloat();
        $price = $this->rounding->apply($unrounded, $rule);
        if (! is_finite($price) || $price <= 0 || $price > 99999999999.9999 || $unrounded > 99999999999.9999) {
            throw new DomainException('A disztribútori kalkuláció nem pozitív vagy túl nagy nettó árat eredményezett.');
        }

        return ['source_wholesale_row_id' => $source->id, 'source_retail_row_id' => $source->source_retail_row_id,
            'source_retail_price' => (float) $source->base_price, 'base_price' => (float) $source->final_price,
            'distributor_discount_percent' => (float) $parameter->value, 'multiplier' => $factor->toFloat(),
            'unrounded_price' => $unrounded, 'calculated_price' => $price, 'pricing_rounding_rule_id' => $rule->id,
            'snapshot' => ['calculated_at' => now()->toISOString(), 'currency' => 'EUR',
                'formula' => 'Végleges nettó nagyker ár × (1 − kedvezmény% / 100) → disztribútori kerekítés = nettó disztribútori ár',
                'source_wholesale_row_id' => $source->id, 'source_retail_row_id' => $source->source_retail_row_id,
                'source_wholesale_price_list' => ['id' => $source->price_list_id, 'code' => $source->priceList->code],
                'price_list' => ['id' => $list->id, 'code' => $list->code],
                'inputs' => ['base_price' => (float) $source->final_price, 'source_retail_price' => (float) $source->base_price,
                    'multiplier' => $factor->toFloat(), 'distributor_discount_percent' => (float) $parameter->value, 'unrounded_price' => $unrounded],
                'distributor_parameter' => $parameter->only(['id', 'scope_type', 'value', 'price_list_id', 'currency_id', 'product_id', 'item_main_group_id', 'material_composition']),
                'rounding' => $rule->only(['id', 'name', 'round_to', 'decimal_places', 'mode', 'adjustment']), 'calculated_price' => $price]];
    }

    public function inputsChanged(PricingCalculationRow $row, array $calculation, bool $includeApproval = true): bool
    {
        if ($row->source_wholesale_row_id != $calculation['source_wholesale_row_id'] || $row->distributor_discount_percent === null
            || BigDecimal::of($row->distributor_discount_percent)->compareTo(BigDecimal::of($calculation['distributor_discount_percent'])) !== 0) {
            return true;
        }
        if ($includeApproval && isset($row->calculation_snapshot['approval']['inputs']['distributor_discount_percent'])
            && (float) $row->calculation_snapshot['approval']['inputs']['distributor_discount_percent'] !== (float) $calculation['distributor_discount_percent']) {
            return true;
        }

        return parent::inputsChanged($row, $calculation, $includeApproval);
    }

    public function assertCurrent(PricingCalculationRow $row): void
    {
        $source = $row->sourceWholesaleRow;
        if (! $source || ! $row->priceList || $source->product_id != $row->product_id || $source->color_key != $row->color_key
            || $row->priceList->currency_id != $row->currency_id
            || $this->inputsChanged($row, $this->calculate($row->pricingProject, $source, $row->priceList), includeApproval: false)) {
            throw new DomainException('A disztribútori kalkuláció forrása hiányzik vagy elavult. Előbb futtasd a disztribútori újraszámítást.');
        }
    }
}
