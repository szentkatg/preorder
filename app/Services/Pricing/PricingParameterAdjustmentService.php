<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingParameter;
use InvalidArgumentException;

class PricingParameterAdjustmentService
{
    public function updateCostPercentParameter(
        PricingCalculationRow $row,
        string $parameterType,
        string $scopeType,
        float $value,
    ): PricingParameter {
        if (! in_array($parameterType, [
            PricingParameter::TYPE_SHIPPING_COST_PERCENT,
            PricingParameter::TYPE_CUSTOMS_PERCENT,
        ], true)) {
            throw new InvalidArgumentException('Ehhez az akcióhoz csak szállítási költség % vagy vám % módosítható.');
        }

        if ($row->price_type !== PricingCalculationRow::TYPE_COST) {
            throw new InvalidArgumentException('Paraméter innen csak bekerülési érték soron módosítható.');
        }

        $row->loadMissing(['product.itemMainGroup', 'supplier']);

        $attributes = [
            'pricing_project_id' => $row->pricing_project_id,
            'parameter_type' => $parameterType,
            'scope_type' => $scopeType,
            'supplier_country_code' => null,
            'item_main_group_id' => null,
            'product_id' => null,
            'price_list_id' => null,
            'currency_id' => null,
        ];

        match ($scopeType) {
            PricingParameter::SCOPE_GLOBAL => null,
            PricingParameter::SCOPE_SUPPLIER_COUNTRY => $attributes['supplier_country_code'] =
                $row->supplier?->country_code
                    ?: throw new InvalidArgumentException('A sorhoz nincs beszállítói ország, ezért ország szinten nem menthető paraméter.'),
            PricingParameter::SCOPE_ITEM_MAIN_GROUP => $attributes['item_main_group_id'] =
                $row->product?->item_main_group_id
                    ?: throw new InvalidArgumentException('A sorhoz nincs főcsoport, ezért főcsoport szinten nem menthető paraméter.'),
            PricingParameter::SCOPE_PRODUCT => $attributes['product_id'] =
                $row->product_id
                    ?: throw new InvalidArgumentException('A sorhoz nincs modell, ezért modell szinten nem menthető paraméter.'),
            default => throw new InvalidArgumentException('Ismeretlen árképzési paraméter szűkítési szint.'),
        };

        return PricingParameter::query()->updateOrCreate(
            $attributes,
            [
                'value' => $value,
                'active' => true,
            ],
        );
    }
}
