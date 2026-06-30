<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'sku_id',
        'quantity',
        'unit_price',
        'line_total',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
    public function getAssortmentContentAttribute(): int
    {
        return (int) ($this->sku?->assortmentComponents()->sum('quantity') ?? 0);
    }
    
    public function getEffectiveQuantityAttribute(): int
    {
        $content = $this->assortment_content;
    
        return $content > 0
            ? $this->quantity * $content
            : $this->quantity;
    }
    }