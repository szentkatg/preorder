<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class OrderSheetType extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
        'name_hu',
        'name_en',
        'active',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function partnerAddresses()
    {
        return $this->belongsToMany(PartnerAddress::class, 'partner_address_order_sheet_type');
    }

    public function __toString(): string
    {
        return $this->translate('name');
    }
}
