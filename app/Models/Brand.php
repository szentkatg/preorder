<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
        'active',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function partnerAddresses()
    {
        return $this->belongsToMany(
            PartnerAddress::class,
            'partner_address_brand'
        );
    }

    public function __toString(): string
    {
        return $this->translate('name');
    }
}