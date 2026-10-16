<?php

namespace App\Models;

use App\Models\Concerns\ProtectsClosedPricingProjectDeletion;
use App\Services\Pricing\RetailCalculationDependencyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingCalculationRow extends Model
{
    use ProtectsClosedPricingProjectDeletion;

    // Keep the displayed multiplier sortable/searchable without changing the stored calculation input.
    public const EFFECTIVE_MULTIPLIER_SQL = 'CASE WHEN base_price > 0 THEN (1.0 * COALESCE(manual_price, retained_price, calculated_price) / NULLIF(base_price, 0)) ELSE NULL END';

    public const WHOLESALE_MULTIPLIER_SQL = 'CASE WHEN base_price > 0 AND COALESCE(manual_price, retained_price, calculated_price) > 0 THEN 1.0 * base_price / NULLIF(COALESCE(manual_price, retained_price, calculated_price), 0) ELSE NULL END';

    public static function netNetMultiplierSql(?float $vat): string
    {
        return $vat !== null && is_finite($vat) && $vat >= 0 && $vat <= 100
            ? '('.self::WHOLESALE_MULTIPLIER_SQL.') / '.json_encode(1 + $vat / 100, JSON_PRESERVE_ZERO_FRACTION)
            : 'NULL';
    }

    public static function costBasedMultiplierSql(): string
    {
        return 'CASE WHEN retail_exchange_rate > 0 THEN'
            .' 1.0 * COALESCE(manual_price, retained_price, calculated_price) * retail_exchange_rate'
            .' / NULLIF('.self::costBasedMultiplierBaseSql().', 0) ELSE NULL END';
    }

    public static function costBasedMultiplierBaseSql(): string
    {
        // The foreign row's base_price is converted HUF retail, not its HUF cost.
        return '(SELECT huf_source.base_price FROM pricing_calculation_rows AS huf_source'
            .' WHERE huf_source.id = pricing_calculation_rows.source_retail_row_id'
            .' AND huf_source.pricing_project_id = pricing_calculation_rows.pricing_project_id'
            .' AND huf_source.product_id = pricing_calculation_rows.product_id'
            .' AND huf_source.color_key = pricing_calculation_rows.color_key'
            ." AND huf_source.price_type = 'retail' AND huf_source.base_price > 0)";

    }

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

    public const TYPE_OUTLET = 'outlet';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MODIFIED_APPROVED = 'modified_approved';

    public const STATUS_APPROVED_ROUND_2 = 'approved_round_2';

    public const STATUS_APPROVED_ROUND_3 = 'approved_round_3';

    public function needsPricingAttention(): bool
    {
        if ($this->requires_review || (float) $this->final_price <= 0) {
            return true;
        }

        // Cost calculations have no approval workflow of their own.
        if ($this->price_type === self::TYPE_COST) {
            return false;
        }

        return ! in_array($this->status, [self::STATUS_APPROVED, self::STATUS_MODIFIED_APPROVED,
            self::STATUS_APPROVED_ROUND_2, self::STATUS_APPROVED_ROUND_3], true)
            || ($this->price_type === self::TYPE_OUTLET && ! $this->outletDiscountIsSufficient());
    }

    protected $fillable = [
        'pricing_project_id',
        'source_retail_row_id',
        'source_wholesale_row_id',
        'distributor_discount_percent',
        'outlet_discount_percent',
        'source_retail_price',
        'retail_exchange_rate',
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
            'distributor_discount_percent' => 'decimal:6',
            'outlet_discount_percent' => 'decimal:6',
            'source_retail_price' => 'decimal:4',
            'retail_exchange_rate' => 'decimal:6',
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
            if ($row->price_type === self::TYPE_RETAIL && $row->wasChanged(['final_price', 'base_price', 'requires_review',
                'price_list_id', 'pricing_project_id', 'product_id', 'color_key', 'currency_id'])) {
                app(RetailCalculationDependencyService::class)->invalidateForRetailChange($row);
            }
            if ($row->price_type === self::TYPE_WHOLESALE && $row->wasChanged([
                'final_price', 'base_price', 'requires_review', 'source_retail_row_id', 'pricing_project_id', 'product_id', 'color_key', 'currency_id',
            ])) {
                $row->distributorRows()->update(['requires_review' => true]);
            }
        });
        static::deleting(function (PricingCalculationRow $row): void {
            if ($row->price_type === self::TYPE_RETAIL) {
                app(RetailCalculationDependencyService::class)->invalidateForRetailChange($row);
            } elseif ($row->price_type === self::TYPE_WHOLESALE) {
                $row->distributorRows()->update(['requires_review' => true]);
            } elseif ($row->price_type === self::TYPE_COST && (int) $row->price_list_key === 0) {
                app(RetailCalculationDependencyService::class)->invalidateForCostChange($row);
            }
        });
    }

    public function pricingProject(): BelongsTo
    {
        return $this->belongsTo(PricingProject::class);
    }

    public function sourceRetailRow(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_retail_row_id');
    }

    public function sourceWholesaleRow(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_wholesale_row_id');
    }

    public function distributorRows(): HasMany
    {
        return $this->hasMany(self::class, 'source_wholesale_row_id')->where('price_type', self::TYPE_DISTRIBUTOR);
    }

    public function outletRows(): HasMany
    {
        return $this->hasMany(self::class, 'source_retail_row_id')->where('price_type', self::TYPE_OUTLET);
    }

    public function getActualOutletDiscountAttribute(): ?float
    {
        return $this->price_type === self::TYPE_OUTLET && (float) $this->base_price > 0 && $this->final_price !== null
            ? (1 - (float) $this->final_price / (float) $this->base_price) * 100 : null;
    }

    public function outletDiscountIsSufficient(mixed $price = null): bool
    {
        $price ??= $this->final_price;
        if ($this->price_type !== self::TYPE_OUTLET || $price === null || ! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0 || (float) $this->base_price <= 0
            || $this->outlet_discount_percent === null || (float) $this->outlet_discount_percent < 0 || (float) $this->outlet_discount_percent >= 100) {
            return false;
        }
        $ceiling = BigDecimal::of($this->base_price)->multipliedBy(BigDecimal::one()
            ->minus(BigDecimal::of($this->outlet_discount_percent)->dividedBy(100, 8, RoundingMode::HALF_UP)));

        return BigDecimal::of((string) $price)->compareTo($ceiling) <= 0;
    }

    public function retailCostSource(): BelongsTo
    {
        // A separate read-only projection avoids loading every source snapshot for the grid,
        // without giving calculation/approval services a partially loaded sourceRetailRow.
        return $this->belongsTo(self::class, 'source_retail_row_id')
            ->select(['id', 'pricing_project_id', 'product_id', 'color_key', 'price_type', 'base_price']);
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

    public function getEffectiveWholesaleMultiplierAttribute(): ?float
    {
        $price = $this->manual_price ?? $this->retained_price ?? $this->calculated_price;

        return (float) $this->base_price > 0 && (float) $price > 0 ? (float) $this->base_price / (float) $price : null;
    }

    public function getCostBasedMultiplierAttribute(): ?float
    {
        $price = $this->manual_price ?? $this->retained_price ?? $this->calculated_price;
        $source = $this->retailCostSource;
        if ($price === null || (float) $this->retail_exchange_rate <= 0 || ! $source
            || $source->pricing_project_id != $this->pricing_project_id || $source->product_id != $this->product_id
            || $source->color_key != $this->color_key || $source->price_type !== self::TYPE_RETAIL
            || (float) $source->base_price <= 0) {
            return null;
        }

        return (float) $price * (float) $this->retail_exchange_rate / (float) $source->base_price;
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
