<?php

namespace App\Services\Partner;

use App\Models\Season;

class PartnerSeasonContext
{
    public const SESSION_KEY = 'partner.season_id';

    public static function current(): ?Season
    {
        $id = session(self::SESSION_KEY);

        return is_numeric($id) ? Season::query()->visibleToPartners()->find((int) $id) : null;
    }

    public static function season(): Season
    {
        $season = self::current();
        abort_unless($season, 404);

        return $season;
    }

    public static function id(): int
    {
        return (int) self::season()->id;
    }

    public static function select(int $seasonId): void
    {
        $season = Season::query()->visibleToPartners()->findOrFail($seasonId);
        session()->forget(['partner_order_selector', 'order_selector']);
        session([self::SESSION_KEY => (int) $season->id]);
    }

    public static function assertMatches(?int $seasonId): void
    {
        abort_unless($seasonId !== null && $seasonId === self::id(), 404);
    }
}
