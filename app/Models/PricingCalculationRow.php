<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingCalculationRow extends Model
{
    // Keep the displayed multiplier sortable/searchable without changing the stored calculation input.
    public const EFFECTIVE_MULTIPLIER_SQL = 'CASE WHEN manual_price IS NULL AND retained_price IS NULL THEN multiplier WHEN base_price > 0 THEN (1.0 * COALESCE(manual_price, retained_price) / NULLIF(base_price, 0)) ELSE NULL END';

    public const TYPE_COST = 'cost';

    public const TYPE_RETAIL = 'retail';

    public const TYPE_WHOLESALE = 'wholesale';

    public const TYPE_DISTRIBUTOR = 'distributor';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MODIFIED_APPROVED = 'modified_approved';

    public const STATUS_APPROVED_ROUND_2 = 'approved_round_2';

    public const STATUS_APPROVED_ROUND_3 = 'approved_round_3';

    protected $fillable = [
        'pricing_project_id',
        'product_id',
        'color_id',
        'product_purchase_price_id',
        'supplier_id',
        'purchase_currency_id',
        'price_list_id',
        'price_type',
        'currency_id',
        'purchase_price',
        'exchange_rate',
        'shipping_cost_percent',
        'customs_percent',
        'candidate_count',
        'base_price',
        'multiplier',
        'unrounded_price',
        'pricing_rounding_rule_id',
        'retained_price',
        'requires_review',
        'calculated_price',
        'manual_price',
        'final_price',
        'status',
        'calculation_snapshot',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'calculated_price' => 'decimal:4',
            'manual_price' => 'decimal:4',
            'final_price' => 'decimal:4',
            'purchase_price' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'shipping_cost_percent' => 'decimal:4',
            'customs_percent' => 'decimal:4',
            'base_price' => 'decimal:4',
            'multiplier' => 'decimal:6',
            'unrounded_price' => 'decimal:4',
            'retained_price' => 'decimal:4',
            'requires_review' => 'boolean',
            'calculation_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PricingCalculationRow $row): void {
            $row->color_key = $row->color_id
                ? (int) $row->color_id
                : 0;

            $row->price_list_key = $row->price_list_id
                ? (int) $row->price_list_id
                : 0;

            $row->final_price = $row->manual_price ?? $row->retained_price ?? $row->calculated_price;
        });
    }

    public function pricingProject(): BelongsTo
    {
        return $this->belongsTo(PricingProject::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function purchasePrice(): BelongsTo
    {
        return $this->belongsTo(
            ProductPurchasePrice::class,
            'product_purchase_price_id',
            'product_purchase_price_id'
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(
            Supplier::class,
            'supplier_id',
            'supplier_id'
        );
    }

    public function purchaseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'purchase_currency_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function pricingRoundingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRoundingRule::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hasManualOverride(): bool
    {
        return $this->manual_price !== null;
    }

    public function getEffectiveMultiplierAttribute(): ?float
    {
        $override = $this->manual_price ?? $this->retained_price;

        if ($override === null) {
            return $this->multiplier === null ? null : (float) $this->multiplier;
        }

        return $this->base_price !== null && (float) $this->base_price > 0
            ? (float) $override / (float) $this->base_price
            : null;
    }

    public function finalPriceDifferenceDirection(): ?string
    {
        if ($this->final_price === null || $this->calculated_price === null) {
            return null;
        }

        return (float) $this->final_price < (float) $this->calculated_price
            ? 'down'
            : ((float) $this->final_price > (float) $this->calculated_price ? 'up' : null);
    }

    public function priceDifferenceDirection(): ?string
    {
        if ($this->manual_price === null || $this->calculated_price === null) {
            return null;
        }

        return (float) $this->manual_price < (float) $this->calculated_price
            ? 'down'
            : ((float) $this->manual_price > (float) $this->calculated_price ? 'up' : null);
    }
}
