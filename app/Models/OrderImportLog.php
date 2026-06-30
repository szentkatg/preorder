<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderImportLog extends Model
{
    protected $fillable = [
        'partner_user_id',
        'order_id',
        'user_id',
        'partner_id',
        'partner_address_id',
        'address_code',
        'filename',
        'status',
        'created_count',
        'updated_count',
        'deleted_count',
        'unchanged_count',
        'invalid_count',
        'changed_count',
        'warnings',
        'error_message',
        'imported_at',
    ];

    protected $casts = [
        'warnings' => 'array',
        'imported_at' => 'datetime',
    ];
    
    public function partnerUser()
    {
        return $this->belongsTo(PartnerUser::class);
    }   

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerAddress(): BelongsTo
    {
        return $this->belongsTo(PartnerAddress::class);
    }
}
