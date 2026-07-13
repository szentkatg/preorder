<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'erp_partner_code',
        'name',
        'short_name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function purchasePrices(): HasMany
    {
        return $this->hasMany(ProductPurchasePrice::class);
    }
}