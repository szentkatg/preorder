<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPool extends Model
{
    protected $table = 'partnerpool';

    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'row_key', 'erp_partner_code', 'partner_name', 'short_name', 'partner_type',
        'partner_email', 'partner_phone', 'sales_rep_erp_partner_code', 'partner_active',
        'addrid', 'address_name', 'country', 'zip', 'city', 'street', 'contact_name',
        'address_email', 'address_phone', 'address_active', 'is_default',
        'currency_code', 'language_code', 'price_list_code', 'brand_codes', 'order_sheet_type_codes',
        'address_type', 'source_extracted_at',
    ];

    protected function casts(): array
    {
        return ['partner_active' => 'boolean', 'address_active' => 'boolean', 'is_default' => 'boolean',
            'brand_codes' => 'array', 'order_sheet_type_codes' => 'array', 'source_extracted_at' => 'datetime'];
    }

    public function scopeCurrentSnapshot(Builder $query): Builder
    {
        return $query->where('batch_id', PartnerPoolSyncState::select('active_batch_id')->whereKey(1));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'erp_partner_code', 'erp_partner_code');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'erp_partner_code', 'erp_partner_code');
    }

    public function permits(string $type): bool
    {
        return $this->partner_type === 'both' || $this->partner_type === $type;
    }
}
