<?php

namespace App\Services\Pricing;

use App\Models\PricingParameter;
use App\Models\Product;
use Illuminate\Support\Collection;

class PricingParameterResolver
{
    public function resolve(
        int $pricingProjectId,
        string $parameterType,
        Product $product,
        ?string $supplierCountryCode = null,
        ?int $priceListId = null,
        ?int $currencyId = null,
        bool $requireUnique = false,
    ): ?PricingParameter {
        $parameters = PricingParameter::query()
            ->where('pricing_project_id', $pricingProjectId)
            ->where('parameter_type', $parameterType)
            ->where('active', true)
            ->where(function ($query) use ($priceListId): void {
                $query->whereNull('price_list_id');

                if ($priceListId !== null) {
                    $query->orWhere('price_list_id', $priceListId);
                }
            })
            ->where(function ($query) use ($currencyId): void {
                $query->whereNull('currency_id');

                if ($currencyId !== null) {
                    $query->orWhere('currency_id', $currencyId);
                }
            })
            ->where(function ($query) use ($product, $supplierCountryCode): void {
                $query->where('scope_type', PricingParameter::SCOPE_GLOBAL)
                    ->orWhere(function ($query) use ($supplierCountryCode): void {
                        $query->where('scope_type', PricingParameter::SCOPE_SUPPLIER_COUNTRY)
                            ->where('supplier_country_code', $supplierCountryCode);
                    })
                    ->orWhere(function ($query) use ($product): void {
                        $query->where('scope_type', PricingParameter::SCOPE_ITEM_MAIN_GROUP)
                            ->where('item_main_group_id', $product->item_main_group_id);
                    })
                    ->orWhere('scope_type', PricingParameter::SCOPE_ITEM_MAIN_GROUP_PATTERN)
                    ->orWhere(function ($query) use ($product): void {
                        $query->where('scope_type', PricingParameter::SCOPE_MATERIAL_COMPOSITION)
                            ->whereNotNull('material_composition')
                            ->where('material_composition', '!=', '')
                            ->where('material_composition', $product->material_composition ?: '');
                    })
                    ->orWhere(function ($query) use ($product): void {
                        $query->where('scope_type', PricingParameter::SCOPE_PRODUCT)
                            ->where('product_id', $product->id);
                    });
            })
            ->get()->filter(fn (PricingParameter $parameter): bool => $parameter->scope_type !== PricingParameter::SCOPE_ITEM_MAIN_GROUP_PATTERN
                || ItemMainGroupCodePattern::matches((string) $parameter->item_main_group_pattern, $product->itemMainGroup?->code));

        $sorted = $this->sortBySpecificity($parameters);
        if ($sorted->count() > 1 && $this->specificity($sorted[0]) === $this->specificity($sorted[1])
            && ($requireUnique || $sorted[0]->scope_type === PricingParameter::SCOPE_ITEM_MAIN_GROUP_PATTERN)) {
            throw new \DomainException('Több azonos szintű aktív árképzési paraméter van. Csak egy maradjon aktív.');
        }

        return $sorted->first();
    }

    /**
     * @param  Collection<int, PricingParameter>  $parameters
     * @return Collection<int, PricingParameter>
     */
    private function sortBySpecificity(Collection $parameters): Collection
    {
        return $parameters->sortByDesc(fn (PricingParameter $parameter): array => $this->specificity($parameter))->values();
    }

    private function specificity(PricingParameter $parameter): array
    {
        return [$this->scopeWeight($parameter) + ($parameter->price_list_id ? 10 : 0) + ($parameter->currency_id ? 5 : 0),
            ...($parameter->scope_type === PricingParameter::SCOPE_ITEM_MAIN_GROUP_PATTERN
                ? ItemMainGroupCodePattern::specificity($parameter->item_main_group_pattern) : [0, 0, 0])];
    }

    private function scopeWeight(PricingParameter $parameter): int
    {
        return match ($parameter->scope_type) {
            PricingParameter::SCOPE_PRODUCT => 400,
            PricingParameter::SCOPE_MATERIAL_COMPOSITION => 350,
            PricingParameter::SCOPE_ITEM_MAIN_GROUP => 300,
            PricingParameter::SCOPE_ITEM_MAIN_GROUP_PATTERN => 250,
            PricingParameter::SCOPE_SUPPLIER_COUNTRY => 200,
            default => 100,
        };
    }
}
