<?php

namespace App\Models;

use App\Notifications\PartnerResetPasswordNotification;
use App\Services\Partner\PartnerAccessService;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PartnerUser extends Authenticatable implements CanResetPasswordContract
{
    use Notifiable, CanResetPassword;

    public const ROLE_PARTNER_ADMIN = 'partner_admin';
    public const ROLE_ADDRESS_USER = 'address_user';
    public const ROLE_SALES_REP = 'sales_rep';

    protected $fillable = [
        'partner_id',
        'role',
        'name',
        'email',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'active' => 'boolean',
        'password' => 'hashed',
    ];

    public function sendPasswordResetNotification($token): void
    {

        $this->notify(new PartnerResetPasswordNotification($token));
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function addresses(): BelongsToMany
    {
        return $this->belongsToMany(
            PartnerAddress::class,
            'partner_user_addresses',
            'partner_user_id',
            'partner_address_id'
        )->withTimestamps();
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(
            Partner::class,
            'partner_user_partners',
            'partner_user_id',
            'partner_id'
        )->withTimestamps();
    }

    public function accessibleOrdersQuery(): Builder
    {
        return app(PartnerAccessService::class)
            ->accessibleOrdersQuery($this);
    }

    public function accessiblePartnersQuery(): Builder
    {
        return app(PartnerAccessService::class)
            ->accessiblePartnersQuery($this);
    }

    public function accessibleAddressesQuery(): Builder
    {
        return app(PartnerAccessService::class)
            ->accessibleAddressesQuery($this);
    }

    public function canAccessPartner(Partner $partner): bool
    {
        return app(PartnerAccessService::class)
            ->canAccessPartner($this, $partner);
    }

    public function canAccessAddress(PartnerAddress $address): bool
    {
        return app(PartnerAccessService::class)
            ->canAccessAddress($this, $address);
    }

    public function canAccessOrder(Order $order): bool
    {
        return app(PartnerAccessService::class)
            ->canAccessOrder($this, $order);
    }
    
    public static function exportColumns(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Név',
            'email' => 'E-mail',
            'role' => 'Szerepkör',
            'partner.erp_partner_code' => 'Saját partner ERP kód',
            'partner.name' => 'Saját partner',
    
            'address_codes' => [
                'label' => 'Hozzárendelt címkódok',
                'value' => fn (self $user) => $user->addresses
                    ->pluck('addrid')
                    ->filter()
                    ->implode(', '),
            ],
    
            'address_names' => [
                'label' => 'Hozzárendelt címek',
                'value' => fn (self $user) => $user->addresses
                    ->pluck('name')
                    ->filter()
                    ->implode(', '),
            ],
    
            'partner_codes' => [
                'label' => 'Hozzárendelt partner ERP kódok',
                'value' => fn (self $user) => $user->partners
                    ->pluck('erp_partner_code')
                    ->filter()
                    ->implode(', '),
            ],
    
            'partner_names' => [
                'label' => 'Hozzárendelt partnerek',
                'value' => fn (self $user) => $user->partners
                    ->pluck('name')
                    ->filter()
                    ->implode(', '),
            ],
    
            'active' => [
                'label' => 'Aktív',
                'value' => fn (self $user) => $user->active ? 'Igen' : 'Nem',
            ],
    
            'created_at' => [
                'label' => 'Létrehozva',
                'value' => fn (self $user) => optional($user->created_at)->format('Y-m-d H:i:s'),
            ],
        ];
    }
    
    public static function exportRelations(): array
    {
        return [
            'partner',
            'addresses',
            'partners',
        ];
    }
    
    public static function exportStringColumns(): array
    {
        return [
            'partner.erp_partner_code',
            'address_codes',
            'partner_codes',
        ];
    }
    
    public static function exportOrderBy(): ?string
    {
        return 'name';
    }    
    
}
