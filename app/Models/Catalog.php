<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    protected $fillable = [
        'season_id',
        'brand_id',
        'order_sheet_type_id',
        'name',
        'pdf_file',
        'image_folder',
        'page_offset',
        'active',
    ];

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function orderSheetType()
    {
        return $this->belongsTo(OrderSheetType::class);
    }
}