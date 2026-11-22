<?php

namespace App\Services\Partner;

use App\Models\PartnerUser;
use Illuminate\Support\Facades\DB;

class PartnerPortalNotice
{
    public const KEY = 'portal-update-2026-11-22';

    public function shouldShow(PartnerUser $user): bool
    {
        return ! DB::table('partner_user_notice_dismissals')
            ->where('partner_user_id', $user->id)
            ->where('notice_key', self::KEY)->exists();
    }

    public function dismiss(PartnerUser $user): void
    {
        DB::table('partner_user_notice_dismissals')->insertOrIgnore([
            'partner_user_id' => $user->id,
            'notice_key' => self::KEY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
