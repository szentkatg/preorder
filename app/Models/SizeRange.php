<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeRange extends Model
{
    protected $fillable = [
        'code',
        'matrix_group',
        'name_hu',
        'name_en',
        'sort_order',
        'active',
    ];

    public function items()
    {
        return $this->hasMany(SizeRangeItem::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function __toString(): string
    {
        return $this->code;
    }
}