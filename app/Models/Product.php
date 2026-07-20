<?php

namespace App\Models;
use App\Models\Season;
use App\Models\ItemMainGroup;
use App\Models\SizeRange;
use App\Models\Color;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'season_id',
        'item_main_group_id',
        'size_range_id',
        'model_code',
        'name_hu',
        'name_en',
        'brand_id',
        'order_sheet_type_id',
        'catalog_group_name_hu',
        'catalog_group_name_en',
        'catalog_group_sort',
        'catalog_sort',
        'catalog_page',
        'rounding_rule_id',
        'promised_delivery_date',
        'active',
    ];

        public function season()
    {
        return $this->belongsTo(Season::class);
    }

	public function purchasePrices(): HasMany
	{
		return $this->hasMany(ProductPurchasePrice::class);
	}

    public function itemMainGroup()
    {
        return $this->belongsTo(ItemMainGroup::class);
    }
    
    public function sizeRange()
    {
        return $this->belongsTo(SizeRange::class);
    }
    
    public function colors()
    {
        return $this->hasMany(Color::class);
    }
    
    public function skus()
    {
        return $this->hasMany(Sku::class);
    }
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
    
    public function orderSheetType()
    {
        return $this->belongsTo(OrderSheetType::class);
    }
    public function colorImages()
    {
        return $this->hasMany(ColorImage::class);
    }
    public function roundingRule()
    {
        return $this->belongsTo(
            RoundingRule::class,
            'rounding_rule_id',
            'rounding_rule_id'
        );
    }
}