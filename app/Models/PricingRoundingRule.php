<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRoundingRule extends Model
{
    public const MODE_NEAREST = 'nearest';

    public const MODE_UP = 'up';

    public const MODE_DOWN = 'down';

    protected $fillable = [
        'pricing_project_id',
        'name',
        'price_type',
        'price_list_id',
        'currency_id',
        'round_to',
        'decimal_places',
        'mode',
        'adjustment',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'round_to' => 'decimal:4',
            'adjustment' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function pricingProject(): BelongsTo
    {
        return $this->belongsTo(PricingProject::class);
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
