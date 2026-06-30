<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColorImage extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'image_url',
        'sort_order',
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
}