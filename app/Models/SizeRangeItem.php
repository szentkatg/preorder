<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeRangeItem extends Model
{
    protected $fillable = [
        'size_range_id',
        'size_id',
        'sort_order',
    ];

    public function sizeRange()
    {
        return $this->belongsTo(SizeRange::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class);
    }
}