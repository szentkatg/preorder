<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'season_id',
        'partner_id',
        'partner_address_id',
        'brand_id',
        'order_sheet_type_id',
        'reference_number',
        'order_type_id',
        'sordid',
        'price_list_id',
        'currency_id',
        'language_id',
        'status',
        'notes',
        'submitted_at',
    ];

    public function setReferenceNumberAttribute(mixed $value): void
    {
        $this->attributes['reference_number'] = is_string($value)
            ? trim($value)
            : $value;
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerAddress()
    {
        return $this->belongsTo(PartnerAddress::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function orderSheetType()
    {
        return $this->belongsTo(OrderSheetType::class);
    }

    public function orderType()
    {
        return $this->belongsTo(OrderType::class);
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getTotalOrderedUnitsAttribute(): int
    {
        return $this->items->sum('quantity');
    }

    public function getTotalEffectiveQuantityAttribute(): int
    {
        return $this->items->sum(fn ($item) => $item->effective_quantity);
    }

    public function getTotalValueAttribute(): float
    {
        return $this->items->sum('line_total');
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
