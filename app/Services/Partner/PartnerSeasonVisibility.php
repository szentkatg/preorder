<?php

namespace App\Services\Partner;

use App\Models\Season;

class PartnerSeasonVisibility
{
    public static function isVisible(?int $seasonId): bool
    {
        return $seasonId !== null && Season::query()->visibleToPartners()->whereKey($seasonId)->exists();
    }

    public static function assertVisible(?int $seasonId): void
    {
        // Re-read the flag; a model or open Livewire page may predate hiding it.
        abort_unless(self::isVisible($seasonId), 404);
    }
}
