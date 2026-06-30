<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Size extends Model
{
    protected $fillable = [
        'code',
        'sort_order',
        'active',
    ];

    public function sizeRangeItems()
    {
        return $this->hasMany(SizeRangeItem::class);
    }

    public function skus()
    {
        return $this->hasMany(Sku::class);
    }

    public function __toString(): string
    {
        return $this->code;
    }
}