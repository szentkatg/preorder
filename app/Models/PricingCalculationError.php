<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingCalculationError extends Model
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    protected $fillable = [
        'run_id',
        'pricing_project_id',
        'price_type',
        'product_id',
        'color_id',
        'product_purchase_price_id',
        'supplier_id',
        'severity',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
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
}
