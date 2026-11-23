<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Sku extends Model
{
    public function scopeForPartnerOrder(Builder $query, Order $order): Builder
    {
        return $query->whereHas('product', fn (Builder $product) => $product->visibleToPartners()
            ->where('season_id', $order->season_id)->where('brand_id', $order->brand_id)
            ->where('order_sheet_type_id', $order->order_sheet_type_id));
    }

    protected $fillable = [
        'product_id',
        'color_id',
        'size_id',
        'sku_code',
        'sku_name',
        'type',
        'active',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class);
    }

    public function assortmentComponents()
    {
        return $this->hasMany(ItemAssortment::class, 'assortment_sku_id');
    }

    public function assortmentMemberships()
    {
        return $this->hasMany(ItemAssortment::class, 'component_sku_id');
    }

    public function __toString(): string
    {
        return $this->sku_code;
    }
}
