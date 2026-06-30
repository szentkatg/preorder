<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Partner extends Model
{
    protected $fillable = [
        'name',
        'erp_partner_code',
        'email',
        'phone',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(PartnerAddress::class);
    }
    public function users(): HasMany
    {
        return $this->hasMany(PartnerUser::class);
    }
    public function language()
    {
        return $this->belongsTo(Language::class);
    }
    
    public static function exportColumns(): array
    {
        return [
            'id' => 'ID',
            'erp_partner_code' => 'Partner ERP kód',
            'name' => 'Partner',
            'addrid' => 'Címkód',
            'name' => 'Cím név',
            'zipcode' => 'Irányítószám',
            'city' => 'Város',
            'address' => 'Cím',
            'email' => 'E-mail',
            'phone' => 'Telefon',
            'language.code' => 'Nyelv',
            'priceList.name' => 'Árlista',
            'active' => [
                'label' => 'Aktív',
                'value' => fn (self $address) => $address->active ? 'Igen' : 'Nem',
            ],
            'created_at' => [
                'label' => 'Létrehozva',
                'value' => fn (self $address) => optional($address->created_at)->format('Y-m-d H:i:s'),
            ],
        ];
    }
    
    public static function exportRelations(): array
    {
       return [];
    }
    
    public static function exportStringColumns(): array
    {
        return [
            'erp_partner_code',
            'addrid',
            'phone',
        ];
    }
    
    public static function exportOrderBy(): ?string
    {
        return 'name';
    }    
    
}