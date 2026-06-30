<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $fillable = [
        'code',
        'name_hu',
        'name_en',
        'type',
        'season_id',
        'currency_id',
        'retail_price_list_id',
        'active',
    ];

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function retailPriceList()
    {
        return $this->belongsTo(
            PriceList::class,
            'retail_price_list_id'
        );
    }

    public function wholesalePriceLists()
    {
        return $this->hasMany(
            PriceList::class,
            'retail_price_list_id'
        );
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function __toString(): string
    {
        return $this->code . ' - ' . $this->name_hu;
    }
}