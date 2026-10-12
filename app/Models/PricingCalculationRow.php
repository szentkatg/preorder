<?php

namespace App\Models;

use App\Services\Pricing\RetailCalculationDependencyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingCalculationRow extends Model
{
    // Keep the displayed multiplier sortable/searchable without changing the stored calculation input.
    public const EFFECTIVE_MULTIPLIER_SQL = 'CASE WHEN base_price > 0 THEN (1.0 * COALESCE(manual_price, retained_price, calculated_price) / NULLIF(base_price, 0)) ELSE NULL END';

    public static function targetPurchasePriceSql(): string
    {
        $price = 'COALESCE(manual_price, retained_price, calculated_price)';
        $scaled = '1.0 * purchase_price * '.$price.' / NULLIF(base_price * multiplier, 0) * 10000';
        // The local SQLite build has no optional math functions. Values are nonnegative, so truncation is floor.
        $rounded = (new static)->getConnection()->getDriverName() === 'sqlite'
            ? 'CAST(('.$scaled.') AS INTEGER)' : 'FLOOR('.$scaled.')';

        return 'CASE WHEN purchase_price IS NULL OR purchase_price < 0 THEN NULL'
            .' WHEN manual_price IS NULL AND retained_price IS NULL THEN purchase_price'
            .' WHEN base_price IS NULL OR base_price <= 0 OR multiplier IS NULL OR multiplier <= 0 OR '.$price.' <= 0 THEN NULL'
            .' WHEN '.$price.' >= base_price * multiplier THEN purchase_price ELSE '.$rounded.' / 10000.0 END';
    }

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

        static::updated(function (PricingCalculationRow $row): void {
            if ((($row->price_type === self::TYPE_COST && (int) $row->price_list_key === 0)
                    || ($row->getOriginal('price_type') === self::TYPE_COST && (int) $row->getOriginal('price_list_key') === 0))
                && $row->wasChanged(RetailCalculationDependencyService::COST_INPUT_FIELDS)) {
                app(RetailCalculationDependencyService::class)->invalidateForCostChange($row);
            }
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
        $price = $this->manual_price ?? $this->retained_price ?? $this->calculated_price;

        return $price !== null && $this->base_price !== null && (float) $this->base_price > 0
            ? (float) $price / (float) $this->base_price
            : null;
    }

    public function getTargetPurchasePriceAttribute(): ?string
    {
        if ($this->purchase_price === null || (float) $this->purchase_price < 0) {
            return null;
        }
        if ($this->manual_price === null && $this->retained_price === null) {
            return $this->purchase_price;
        }

        $price = $this->manual_price ?? $this->retained_price;
        if ($this->base_price === null || (float) $this->base_price <= 0
            || $this->multiplier === null || (float) $this->multiplier <= 0 || (float) $price <= 0) {
            return null;
        }

        $original = BigDecimal::of($this->purchase_price);
        $target = $original->multipliedBy($price)->dividedBy(
            BigDecimal::of($this->base_price)->multipliedBy($this->multiplier),
            4, RoundingMode::DOWN,
        );

        return $target->isLessThan($original) ? (string) $target : $this->purchase_price;
    }

    public function needsPurchasePriceNegotiation(): bool
    {
        $target = $this->target_purchase_price;

        return $target !== null && $this->purchase_price !== null
            && BigDecimal::of($target)->isLessThan(BigDecimal::of($this->purchase_price));
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
