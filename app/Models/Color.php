<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Color extends Model
{
    protected $fillable = [
        'product_id',
        'code',
        'name_hu',
        'name_en',
        'sort_order',
        'active',
    ];

	public function purchasePrices(): HasMany
	{
		return $this->hasMany(ProductPurchasePrice::class);
	}

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function skus()
    {
        return $this->hasMany(Sku::class);
    }

    public function itemAssortments()
    {
        return $this->hasMany(ItemAssortment::class);
    }

    public function __toString(): string
    {
        return $this->code . ' - ' . $this->name_hu;
    }
    public function images()
    {
        return $this->hasMany(ColorImage::class);
    }
}