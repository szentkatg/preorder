<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
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
        return $this->translate('name');
    }
}
