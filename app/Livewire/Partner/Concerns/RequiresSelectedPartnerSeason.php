<?php

namespace App\Livewire\Partner\Concerns;

use App\Models\Season;
use App\Services\Partner\PartnerSeasonContext;

trait RequiresSelectedPartnerSeason
{
    public function bootRequiresSelectedPartnerSeason(): void
    {
        PartnerSeasonContext::season();
    }

    public function hydrateRequiresSelectedPartnerSeason(): void
    {
        // An old browser tab must not keep working in a previous login's season.
        PartnerSeasonContext::assertMatches($this->seasonId ?? $this->summarySeasonId ?? PartnerSeasonContext::id());
    }

    public function getSelectedSeasonProperty(): Season
    {
        return PartnerSeasonContext::season();
    }
}
