<?php

namespace App\Models;

use App\Exceptions\PricingParameterConflictException;
use App\Models\Concerns\ProtectsClosedPricingProjectDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingParameter extends Model
{
    use ProtectsClosedPricingProjectDeletion;

    public const TYPE_SHIPPING_COST_PERCENT = 'shipping_cost_percent';

    public const TYPE_CUSTOMS_PERCENT = 'customs_percent';

    public const TYPE_RETAIL_MULTIPLIER = 'retail_multiplier';

    public const TYPE_COUNTRY_MULTIPLIER = 'country_multiplier';

    public const TYPE_WHOLESALE_MARGIN = 'wholesale_margin';

    public const TYPE_DISTRIBUTOR_DISCOUNT = 'distributor_discount';

    public const TYPE_OUTLET_DISCOUNT = 'outlet_discount';

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_SUPPLIER_COUNTRY = 'supplier_country';

    public const SCOPE_ITEM_MAIN_GROUP = 'item_main_group';

    public const SCOPE_MATERIAL_COMPOSITION = 'material_composition';

    public const SCOPE_PRODUCT = 'product';

    protected $attributes = ['scope_type' => self::SCOPE_GLOBAL, 'active' => true];

    protected $fillable = [
        'pricing_project_id',
        'parameter_type',
        'scope_type',
        'supplier_country_code',
        'item_main_group_id',
        'material_composition',
        'product_id',
        'price_list_id',
        'currency_id',
        'value',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $invalidate = static function (self $parameter): void {
            if ($parameter->parameter_type === self::TYPE_OUTLET_DISCOUNT || $parameter->getOriginal('parameter_type') === self::TYPE_OUTLET_DISCOUNT) {
                foreach ([$parameter->getAttributes(), $parameter->getOriginal()] as $attributes) {
                    if (empty($attributes['pricing_project_id'])) {
                        continue;
                    }
                    PricingCalculationRow::where('price_type', 'outlet')->where('pricing_project_id', $attributes['pricing_project_id'])
                        ->when(($attributes['scope_type'] ?? '') === self::SCOPE_PRODUCT,
                            fn ($query) => $query->where('product_id', $attributes['product_id']))
                        ->when(($attributes['scope_type'] ?? '') === self::SCOPE_ITEM_MAIN_GROUP,
                            fn ($query) => $query->whereHas('product', fn ($product) => $product->where('item_main_group_id', $attributes['item_main_group_id'])))
                        ->when(($attributes['scope_type'] ?? '') === self::SCOPE_MATERIAL_COMPOSITION,
                            fn ($query) => $query->whereHas('product', fn ($product) => $product->where('material_composition', $attributes['material_composition'])))
                        ->when(($attributes['scope_type'] ?? '') === self::SCOPE_SUPPLIER_COUNTRY,
                            fn ($query) => $query->whereHas('supplier', fn ($supplier) => $supplier->where('country_code', $attributes['supplier_country_code'])))
                        ->when($attributes['price_list_id'] ?? null, fn ($query, $id) => $query->where('price_list_id', $id))
                        ->when($attributes['currency_id'] ?? null, fn ($query, $id) => $query->where('currency_id', $id))
                        ->update(['requires_review' => true]);
                }
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /** Only the dimension selected by scope_type belongs to the parameter's identity. */
    public function scopeAttributes(): array
    {
        return [
            'supplier_country_code' => $this->scope_type === self::SCOPE_SUPPLIER_COUNTRY
                ? strtoupper(trim((string) $this->supplier_country_code)) : null,
            'item_main_group_id' => $this->scope_type === self::SCOPE_ITEM_MAIN_GROUP ? $this->item_main_group_id : null,
            'material_composition' => $this->scope_type === self::SCOPE_MATERIAL_COMPOSITION ? $this->material_composition : null,
            'product_id' => $this->scope_type === self::SCOPE_PRODUCT ? $this->product_id : null,
        ];
    }

    /** Match using the database collation, just like the calculation resolver. */
    public function matchingParametersQuery(bool $withinProject = true): Builder
    {
        $query = $this->newQuery()->where($this->only(['parameter_type', 'scope_type', 'price_list_id', 'currency_id']));
        if ($withinProject) {
            $query->where('pricing_project_id', $this->pricing_project_id);
        }
        $field = match ($this->scope_type) {
            self::SCOPE_SUPPLIER_COUNTRY => 'supplier_country_code',
            self::SCOPE_ITEM_MAIN_GROUP => 'item_main_group_id',
            self::SCOPE_MATERIAL_COMPOSITION => 'material_composition',
            self::SCOPE_PRODUCT => 'product_id',
            default => null,
        };
        if ($field !== null) {
            // Ignore stale values in irrelevant dimensions of legacy records too.
            $value = $this->scopeAttributes()[$field];
            if ($field === 'supplier_country_code') {
                $query->whereRaw('UPPER(TRIM(supplier_country_code)) = ?', [$value]);
            } else {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            // All normal model writes share the same project lock, including inline
            // adjustments and bulk copies. Concurrent creates cannot both pass.
            $projectIds = array_filter([$this->getOriginal('pricing_project_id'), $this->pricing_project_id]);
            PricingProject::whereKey(array_unique($projectIds))->orderBy('id')->lockForUpdate()->get();
            $this->fill($this->scopeAttributes());
            if ($this->active) {
                $conflicts = $this->matchingParametersQuery()->where('active', true)
                    ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                    ->orderBy('id')->lockForUpdate()->pluck('id');
                if ($conflicts->isNotEmpty()) {
                    throw new PricingParameterConflictException('Már van aktív paraméter ugyanezzel a típussal, szűkítéssel, árlistával és pénznemmel. Ütköző rekord: #'
                        .$conflicts->implode(', #').'. Módosítsd a meglévőt, vagy inaktiváld valamelyik példányt.');
                }
            }

            return parent::save($options);
        });
    }

    public function pricingProject(): BelongsTo
    {
        return $this->belongsTo(PricingProject::class);
    }

    public function itemMainGroup(): BelongsTo
    {
        return $this->belongsTo(ItemMainGroup::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
