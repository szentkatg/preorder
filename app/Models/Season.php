<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    protected $fillable = [
        'name',
        'code',
        'deadline',
        'active',
        'visible_to_partners',
    ];

    protected $attributes = ['visible_to_partners' => false];

    protected function casts(): array
    {
        return ['visible_to_partners' => 'boolean'];
    }

    public function scopeVisibleToPartners(Builder $query): Builder
    {
        return $query->where('visible_to_partners', true);
    }
}
