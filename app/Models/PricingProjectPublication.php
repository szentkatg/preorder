<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingProjectPublication extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function pricingProject()
    {
        return $this->belongsTo(PricingProject::class);
    }
}
