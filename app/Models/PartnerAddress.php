<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PartnerAddress extends Model
{
    protected $fillable = [
        'partner_id',
        'addrid',
        'name',
        'country',
        'zip',
        'city',
        'street',
        'contact_name',
        'email',
        'phone',
        'allow_assortment_ordering',
        'price_list_id',
        'currency_id',
        'language_id',
        'active',
    ];

    protected $casts = [
        'allow_assortment_ordering' => 'boolean',
        'active' => 'boolean',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'partner_address_brand');
    }

    public function orderSheetTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrderSheetType::class, 'partner_address_order_sheet_type');
    }
    
    public static function exportColumns(): array
    {
        return [
            'id' => 'ID',
            'partner.erp_partner_code' => 'Partner ERP kód',
            'partner.name' => 'Partner',
            'addrid' => 'Címkód',
            'name' => 'Cím név',
            'zip' => 'Irányítószám',
            'city' => 'Város',
            'street' => 'Cím',
            'contact_name' => 'Kontakt név',
            'email' => 'E-mail',
            'phone' => 'Telefon',
            'language.code' => 'Nyelv',
            'priceList.code' => 'Árlista kód',
            'priceList.name_hu' => 'Árlista',
            'currency.code' => 'Pénznem',
            'active' => [
                'label' => 'Aktív',
                'value' => fn (self $address) => $address->active ? 'Igen' : 'Nem',
            ],
            'allow_assortment_ordering' => [
                'label' => 'Gyűjtő rendelés?',
                'value' => fn (self $address) => $address->allow_assortment_ordering ? 'Igen' : 'Nem',
            ],
            'created_at' => [
                'label' => 'Létrehozva',
                'value' => fn (self $address) => optional($address->created_at)->format('Y-m-d H:i:s'),
            ],
        ];
    }
    
    public static function exportRelations(): array
    {
        return [
            'partner',
            'language',
            'priceList',
            'currency'
        ];
    }
    
    public static function exportStringColumns(): array
    {
        return [
            'partner.erp_partner_code',
            'addrid',
            'phone',
        ];
    }
    
    public static function exportOrderBy(): ?string
    {
        return 'name';
    }
    
}