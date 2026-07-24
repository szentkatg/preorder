<?php

namespace App\Livewire\Partner\Concerns;

use App\Models\PartnerAddress;

trait SetsPartnerLocale
{
    protected function setPartnerLocale(
        PartnerAddress|int|null $partnerAddress = null
    ): void
    {
        $languageCode = null;
        $languageId = null;

        if (is_int($partnerAddress)) {
            $partnerAddress = PartnerAddress::query()
                ->with('language')
                ->find($partnerAddress);
        } elseif ($partnerAddress instanceof PartnerAddress) {
            $partnerAddress->loadMissing('language');
        }

        if ($partnerAddress instanceof PartnerAddress) {
            $languageCode = $partnerAddress->language?->code;
            $languageId = $partnerAddress->language_id;
        }

        if (! $languageCode) {
            $partnerUser = auth('partner')->user();

            if ($partnerUser) {
                $fallbackAddress = PartnerAddress::query()
                    ->with('language')
                    ->whereIn(
                        'partner_id',
                        $partnerUser->partners()->pluck('partners.id')
                    )
                    ->orderBy('id')
                    ->first();

                $languageCode = $fallbackAddress?->language?->code;
                $languageId = $fallbackAddress?->language_id;
            }
        }

        $locale = strtolower($languageCode ?: session('partner.locale', 'hu'));

        app()->setLocale($locale);

        session([
            'partner.locale' => $locale,
            'partner.language_id' => $languageId,
        ]);
    }
}
