<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingParameter extends Model
{
    public const TYPE_SHIPPING_COST_PERCENT = 'shipping_cost_percent';

    public const TYPE_CUSTOMS_PERCENT = 'customs_percent';

    public const TYPE_RETAIL_MULTIPLIER = 'retail_multiplier';

    public const TYPE_COUNTRY_MULTIPLIER = 'country_multiplier';

    public const TYPE_WHOLESALE_MARGIN = 'wholesale_margin';

    public const TYPE_DISTRIBUTOR_DISCOUNT = 'distributor_discount';

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
