<?php

namespace App\Livewire\Partner\Concerns;

use App\Models\PartnerAddress;

trait SetsPartnerLocale
{
    protected function setPartnerLocale(
        PartnerAddress|int|null $partnerAddress = null
    ): void {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }
}
