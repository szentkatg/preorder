<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name_hu',
        'name_en',
        'symbol',
        'active',
    ];

	public function productPurchasePrices(): HasMany
	{
		return $this->hasMany(ProductPurchasePrice::class);
	}

    public function priceLists()
    {
        return $this->hasMany(PriceList::class);
    }

    public function __toString(): string
    {
        return $this->code;
    }
}