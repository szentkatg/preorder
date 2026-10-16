<?php

namespace App\Models;

use App\Models\Concerns\ProtectsClosedPricingProjectDeletion;
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

    public const SCOPE_PRODUCT = 'product';

    protected $fillable = [
        'pricing_project_id',
        'parameter_type',
        'scope_type',
        'supplier_country_code',
        'item_main_group_id',
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
                PricingCalculationRow::where('price_type', 'outlet')->whereIn('pricing_project_id', array_filter([
                    $parameter->pricing_project_id, $parameter->getOriginal('pricing_project_id'),
                ]))->update(['requires_review' => true]);
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
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
