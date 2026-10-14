<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\PricingRoundingRule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class ForeignRetailCalculator
{
    public const CURRENCIES = ['EUR', 'CZK', 'RON', 'PLN'];

    public function __construct(
        private readonly PricingParameterResolver $parameters,
        private readonly PricingRoundingService $rounding,
    ) {}

    public function calculate(PricingProject $project, PricingCalculationRow $source, PriceList $list): array
    {
        $source->loadMissing(['currency', 'product', 'supplier']);
        $list->loadMissing('currency');
        if ($source->pricing_project_id != $project->id || $source->price_type !== 'retail'
            || $source->currency?->code !== 'HUF' || ! $source->product?->active
            || $source->product->season_id != $project->season_id || $source->requires_review) {
            throw new DomainException('Az árprojekt aktuális, felülvizsgálatot nem igénylő HUF kisker forrássora szükséges.');
        }
        if (! $list->active || $list->type !== 'retail' || ! in_array($list->currency?->code, self::CURRENCIES, true)) {
            throw new DomainException('Aktív EUR, CZK, RON vagy PLN kisker árlista szükséges.');
        }
        if ($source->final_price === null || (float) $source->final_price <= 0) {
            throw new DomainException('A végleges HUF kisker ár hiányzik vagy nem pozitív.');
        }
        app(RetailCalculationDependencyService::class)->assertCalculationCurrent($source);
        $rate = ExchangeRate::query()->where('season_id', $project->season_id)
            ->where('currency_id', $list->currency_id)->where('active', true)->first();
        if (! $rate || (float) $rate->rate_to_huf <= 0) {
            throw new DomainException('Hiányzó vagy nem pozitív szezonárfolyam: '.$list->currency->code);
        }
        $parameter = $this->parameters->resolve($project->id, PricingParameter::TYPE_COUNTRY_MULTIPLIER,
            $source->product, $source->supplier?->country_code, $list->id, $list->currency_id);
        if (! $parameter || (float) $parameter->value <= 0) {
            throw new DomainException('Hiányzó vagy nem pozitív Országszorzó paraméter.');
        }
        $rule = $this->rounding->resolve($project->id, 'retail', $list->id, $list->currency_id);
        if (! $rule || ($rule->price_list_id === null && $rule->currency_id === null)) {
            throw new DomainException('A devizához vagy árlistához külön kisker kerekítési szabály szükséges.');
        }
        if (! in_array($rule->mode, [PricingRoundingRule::MODE_NEAREST, PricingRoundingRule::MODE_UP, PricingRoundingRule::MODE_DOWN], true)
            || ((float) $rule->round_to <= 0 && ($rule->decimal_places === null || $rule->decimal_places < 0 || $rule->decimal_places > 4))) {
            throw new DomainException('Érvénytelen devizás kisker kerekítési szabály.');
        }
        $base = BigDecimal::of($source->final_price)->dividedBy($rate->rate_to_huf, 12, RoundingMode::HALF_UP);
        $unrounded = $base->multipliedBy($parameter->value)->toFloat();
        $price = $this->rounding->apply($unrounded, $rule);
        if (! is_finite($price) || $price <= 0 || $price > 99999999999.9999
            || $unrounded > 99999999999.9999 || $base->isGreaterThan('99999999999.9999')
            || $base->toScale(4, RoundingMode::HALF_UP)->isLessThanOrEqualTo('0')) {
            throw new DomainException('A devizás kisker kalkuláció nem pozitív vagy túl nagy árat eredményezett.');
        }

        return [
            'source_retail_row_id' => $source->id, 'source_retail_price' => (float) $source->final_price,
            'retail_exchange_rate' => (float) $rate->rate_to_huf,
            'base_price' => $base->toFloat(), 'multiplier' => (float) $parameter->value,
            'unrounded_price' => $unrounded, 'calculated_price' => $price, 'pricing_rounding_rule_id' => $rule->id,
            'snapshot' => [
                'calculated_at' => now()->toISOString(),
                'formula' => 'Végleges HUF kisker ár ÷ szezonárfolyam × országszorzó → kerekítés → korrekció',
                'source_retail_row_id' => $source->id,
                'source_retail_price_list' => ['id' => $source->price_list_id, 'code' => $source->priceList?->code],
                'source_cost_row_id' => $source->calculation_snapshot['source_cost_row_id'] ?? null,
                'source_cost_inputs' => $source->calculation_snapshot['source_cost_inputs'] ?? [],
                'price_list' => ['id' => $list->id, 'code' => $list->code, 'name' => $list->name_hu],
                'currency' => $list->currency->code,
                'inputs' => ['source_retail_price' => (float) $source->final_price,
                    'retail_exchange_rate' => (float) $rate->rate_to_huf, 'base_price' => $base->toFloat(),
                    'multiplier' => (float) $parameter->value, 'unrounded_price' => $unrounded],
                'retail_parameter' => $parameter->only(['id', 'scope_type', 'product_id', 'item_main_group_id',
                    'supplier_country_code', 'price_list_id', 'currency_id', 'value']),
                'rounding' => $rule->only(['id', 'name', 'round_to', 'decimal_places', 'mode', 'adjustment'])
                    + ['rounded_before_adjustment' => $price - (float) $rule->adjustment],
                'calculated_price' => $price,
            ],
        ];
    }
}
