<?php

namespace App\Models;

use App\Services\Pricing\RetailCalculationDependencyService;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $fillable = [
        'code',
        'name_hu',
        'name_en',
        'type',
        'currency_id',
        'retail_price_list_id',
        'source_wholesale_price_list_id',
        'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (PriceList $list): void {
            if ($list->type !== 'distributor') {
                $list->source_wholesale_price_list_id = null;
            } else {
                $list->retail_price_list_id = null;
            }
        });
        static::updated(function (PriceList $list): void {
            if ($list->wasChanged(['type', 'currency_id', 'active', 'retail_price_list_id', 'source_wholesale_price_list_id'])) {
                $list->invalidatePricingDependents();
            }
        });
        static::deleting(fn (PriceList $list) => $list->invalidatePricingDependents());
    }

    private function invalidatePricingDependents(): void
    {
        $sources = PricingCalculationRow::where('price_list_id', $this->id)->where('price_type', 'wholesale')->pluck('id');
        PricingCalculationRow::where('price_type', 'distributor')->where(function ($query) use ($sources): void {
            $query->where('price_list_id', $this->id)->orWhereIn('source_wholesale_row_id', $sources);
        })->update(['requires_review' => true]);
        $retail = PricingCalculationRow::where('price_list_id', $this->id)->where('price_type', 'retail')->pluck('id')->all();
        if ($retail !== []) {
            app(RetailCalculationDependencyService::class)->invalidateRetailDependents($retail);
        }
    }

    public function sourceWholesalePriceList()
    {
        return $this->belongsTo(self::class, 'source_wholesale_price_list_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function retailPriceList()
    {
        return $this->belongsTo(
            PriceList::class,
            'retail_price_list_id'
        );
    }

    public function wholesalePriceLists()
    {
        return $this->hasMany(
            PriceList::class,
            'retail_price_list_id'
        );
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function __toString(): string
    {
        return $this->code.' - '.$this->name_hu;
    }
}
