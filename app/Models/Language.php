<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
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
        return $this->translate('name');
    }
}
