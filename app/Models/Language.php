<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name_hu',
        'name_en',
        'active',
    ];

    public function partnerAddresses()
    {
        return $this->hasMany(PartnerAddress::class);
    }

    public function __toString(): string
    {
        return $this->code;
    }
}