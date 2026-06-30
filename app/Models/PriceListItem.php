<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id',
        'season_id',
        'product_id',
        'net_price',
    ];

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}