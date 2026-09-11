<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShareLink extends Model
{
    protected $fillable = [
        'order_id',
        'token',
        'price_list_id',
        'created_by_partner_user_id',
        'expires_at',
        'last_accessed_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function createdByPartnerUser()
    {
        return $this->belongsTo(PartnerUser::class, 'created_by_partner_user_id');
    }

    public function isAvailable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
