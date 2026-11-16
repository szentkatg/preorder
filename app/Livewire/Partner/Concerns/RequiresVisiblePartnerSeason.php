<?php

namespace App\Livewire\Partner\Concerns;

use App\Livewire\Partner\SharedCatalogGroupOrder;
use App\Services\Partner\PartnerSeasonContext;
use App\Services\Partner\PartnerSeasonVisibility;

trait RequiresVisiblePartnerSeason
{
    public function hydrateRequiresVisiblePartnerSeason(): void
    {
        $this->assertPartnerSeasonVisible();
    }

    protected function assertPartnerSeasonVisible(?int $seasonId = null): void
    {
        $seasonId ??= isset($this->order) ? $this->order->season_id : $this->season->id;
        PartnerSeasonVisibility::assertVisible($seasonId);
        // Token-based public shares are not part of an authenticated work session.
        if (! $this instanceof SharedCatalogGroupOrder) {
            PartnerSeasonContext::assertMatches($seasonId);
        }
    }
}
