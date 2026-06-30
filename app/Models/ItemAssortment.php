<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemAssortment extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'assortment_sku_id',
        'component_sku_id',
        'quantity',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function assortmentSku()
    {
        return $this->belongsTo(Sku::class, 'assortment_sku_id');
    }

    public function componentSku()
    {
        return $this->belongsTo(Sku::class, 'component_sku_id');
    }
}