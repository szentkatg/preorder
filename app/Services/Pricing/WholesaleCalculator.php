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

class WholesaleCalculator
{
    public const CURRENCIES = ['HUF', 'EUR'];

    public function __construct(
        protected readonly PricingParameterResolver $parameters,
        protected readonly PricingRoundingService $rounding,
    ) {}

    public function calculate(PricingProject $project, PricingCalculationRow $source, PriceList $list): array
    {
        $source->loadMissing(['product', 'currency', 'supplier']);
        $list->loadMissing(['currency', 'retailPriceList']);
        if (! $list->active || $list->type !== 'wholesale'
            || ! in_array($list->currency?->code, self::CURRENCIES, true)
            || $source->price_list_id != $list->retail_price_list_id || ! $list->retailPriceList?->active
            || $list->retailPriceList->type !== 'retail' || $list->retailPriceList->currency_id != $list->currency_id
            || $source->pricing_project_id != $project->id || $source->currency_id != $list->currency_id
            || $source->price_type !== PricingCalculationRow::TYPE_RETAIL || $source->requires_review
            || ! $source->product?->active || $source->product->season_id != $project->season_id
            || (float) $source->final_price <= 0) {
            throw new DomainException('Aktuális kisker forrás és azonos pénznemű, helyesen kapcsolt aktív HUF/EUR nagyker árlista szükséges.');
        }
        app(RetailCalculationDependencyService::class)->assertCalculationCurrent($source);
        $parameter = $this->parameters->resolve($project->id, PricingParameter::TYPE_WHOLESALE_MARGIN,
            $source->product, $source->supplier?->country_code, $list->id, $list->currency_id, requireUnique: true);
        if (! $parameter || (float) $parameter->value <= 0) {
            throw new DomainException('Hiányzó vagy nem pozitív Nagyker szorzó paraméter.');
        }
        $rule = $this->rounding->resolve($project->id, PricingCalculationRow::TYPE_WHOLESALE, $list->id, $list->currency_id);
        if (! $rule || ($list->currency->code === 'EUR' && $rule->price_list_id === null && $rule->currency_id === null)
            || ! in_array($rule->mode, [PricingRoundingRule::MODE_NEAREST, PricingRoundingRule::MODE_UP, PricingRoundingRule::MODE_DOWN], true)
            || ((float) $rule->round_to <= 0 && ($rule->decimal_places === null || $rule->decimal_places < 0 || $rule->decimal_places > 4))) {
            throw new DomainException('Érvényes nagyker kerekítési szabály szükséges; EUR-nál árlista- vagy pénznemspecifikusan.');
        }
        // The commercial multiplier converts gross retail directly to NET wholesale.
        // VAT is a separate display-only comparison, never an input to this calculation.
        $unrounded = BigDecimal::of($source->final_price)->dividedBy($parameter->value, 12, RoundingMode::HALF_UP)->toFloat();
        $price = $this->rounding->apply($unrounded, $rule);
        if (! is_finite($price) || $price <= 0 || $price > 99999999999.9999 || $unrounded > 99999999999.9999) {
            throw new DomainException('A nagyker kalkuláció nem pozitív vagy túl nagy nettó árat eredményezett.');
        }

        return ['source_retail_row_id' => $source->id, 'source_retail_price' => (float) $source->final_price,
            'base_price' => (float) $source->final_price, 'multiplier' => (float) $parameter->value,
            'unrounded_price' => $unrounded, 'calculated_price' => $price, 'pricing_rounding_rule_id' => $rule->id,
            'snapshot' => ['calculated_at' => now()->toISOString(),
                'formula' => 'Bruttó kisker ár ÷ nagyker szorzó → kerekítés = nettó nagyker ár',
                'currency' => $list->currency->code, 'source_retail_row_id' => $source->id,
                'source_retail_price_list' => ['id' => $source->price_list_id, 'code' => $list->retailPriceList->code],
                'price_list' => ['id' => $list->id, 'code' => $list->code],
                'inputs' => ['base_price' => (float) $source->final_price, 'multiplier' => (float) $parameter->value, 'unrounded_price' => $unrounded],
                'wholesale_parameter' => $parameter->only(['id', 'scope_type', 'value', 'price_list_id', 'currency_id', 'product_id', 'item_main_group_id', 'material_composition']),
                'rounding' => $rule->only(['id', 'name', 'round_to', 'decimal_places', 'mode', 'adjustment']),
                'calculated_price' => $price]];
    }

    public function inputsChanged(PricingCalculationRow $row, array $calculation, bool $includeApproval = true): bool
    {
        foreach (['source_retail_row_id', 'source_retail_price', 'base_price', 'multiplier', 'unrounded_price', 'calculated_price'] as $field) {
            $precision = $field === 'multiplier' ? 6 : ($field === 'source_retail_row_id' ? 0 : 4);
            if ($row->{$field} === null || BigDecimal::of($row->{$field})->toScale($precision, RoundingMode::HALF_UP)
                ->compareTo(BigDecimal::of($calculation[$field])->toScale($precision, RoundingMode::HALF_UP)) !== 0) {
                return true;
            }
        }
        foreach ([$row->calculation_snapshot ?? [], $includeApproval ? ($row->calculation_snapshot['approval'] ?? []) : []] as $snapshot) {
            if ($snapshot === []) {
                continue;
            }
            foreach (['base_price', 'multiplier', 'unrounded_price'] as $field) {
                if (isset($snapshot['inputs'][$field]) && (float) $snapshot['inputs'][$field] !== (float) $calculation['snapshot']['inputs'][$field]) {
                    return true;
                }
            }
            foreach (['round_to', 'decimal_places', 'mode', 'adjustment'] as $field) {
                if (($snapshot['rounding'][$field] ?? null) != ($calculation['snapshot']['rounding'][$field] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function assertCurrent(PricingCalculationRow $row): void
    {
        $source = $row->sourceRetailRow;
        if (! $source || ! $row->priceList || $source->product_id != $row->product_id || $source->color_key != $row->color_key
            || $row->priceList->currency_id != $row->currency_id) {
            throw new DomainException('A nagyker kalkuláció kisker forrása hiányzik vagy nem megfelelő. Újraszámítás szükséges.');
        }
        if ($this->inputsChanged($row, $this->calculate($row->pricingProject, $source, $row->priceList), includeApproval: false)) {
            throw new DomainException('A nagyker kalkuláció bemenetei megváltoztak. Előbb futtasd a nagyker újraszámítást.');
        }
    }
}
