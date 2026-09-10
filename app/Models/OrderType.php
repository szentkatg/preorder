<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class OrderType extends Model
{
    use HasTranslations;

    protected $fillable = [
        'code',
        'name',
        'include_in_supplier_order',
        'active',
    ];

    protected $casts = [
        'include_in_supplier_order' => 'boolean',
        'active' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function __toString(): string
    {
        return $this->translate('name');
    }
}
