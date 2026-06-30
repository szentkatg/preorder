<?php

namespace App\Livewire\Partner\Concerns;

use App\Models\PartnerAddress;

trait SetsPartnerLocale
{
    protected function setPartnerLocale(?int $partnerAddressId = null): void
    {
        $languageCode = null;

        if ($partnerAddressId) {
            $languageCode = PartnerAddress::query()
                ->with('language')
                ->find($partnerAddressId)
                ?->language
                ?->code;
        }

        if (! $languageCode) {
            $partnerUser = auth('partner')->user();

            if ($partnerUser) {
                $languageCode = PartnerAddress::query()
                    ->with('language')
                    ->whereIn(
                        'partner_id',
                        $partnerUser->partners()->pluck('partners.id')
                    )
                    ->orderBy('id')
                    ->first()
                    ?->language
                    ?->code;
            }
        }

        $locale = strtolower($languageCode ?: session('partner.locale', 'hu'));

        app()->setLocale($locale);

        session(['partner.locale' => $locale]);
    }
}