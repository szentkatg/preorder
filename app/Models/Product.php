<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'season_id',
        'previous_season_product_id',
        'previous_season_match_override',
        'item_main_group_id',
        'size_range_id',
        'model_code',
        'name_hu',
        'name_en',
        'material_composition',
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

    protected function casts(): array
    {
        return ['previous_season_match_override' => 'boolean'];
    }

    public function previousSeasonProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_season_product_id');
    }

    public function pricingProjects(): BelongsToMany
    {
        return $this->belongsToMany(PricingProject::class, 'pricing_project_products')->withTimestamps();
    }

    public function scopeForPricingProject(Builder $query, int $projectId): Builder
    {
        return $query->whereHas('pricingProjects', fn (Builder $projects) => $projects->whereKey($projectId));
    }

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
