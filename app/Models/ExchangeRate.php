<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'season_id',
        'currency_id',
        'rate_to_huf',
        'valid_from',
        'active',
    ];

    protected $casts = [
        'rate_to_huf' => 'decimal:4',
        'valid_from' => 'date',
        'active' => 'boolean',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}