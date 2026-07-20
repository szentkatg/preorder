<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoundingRule extends Model
{
    protected $primaryKey = 'rounding_rule_id';

    protected $fillable = [
        'code',
        'name',
        'include_assortments',
        'rounding_multiple',
        'rounding_mode',
        'round_up_from_remainder',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'include_assortments' => 'boolean',
            'rounding_multiple' => 'integer',
            'round_up_from_remainder' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(
            Product::class,
            'rounding_rule_id',
            'rounding_rule_id'
        );
    }
}