<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPurchasePrice extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'supplier_id',
        'currency_id',
        'purchase_price',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

	protected static function booted(): void
	{
		static::saving(function (ProductPurchasePrice $price): void {
			$price->color_key = $price->color_id
				? (int) $price->color_id
				: 0;
		});
	}

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}