<?php

namespace App\Http\Middleware;

use App\Services\Partner\PartnerSeasonContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePartnerSeason
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PartnerSeasonContext::current()) {
            $request->session()->forget(PartnerSeasonContext::SESSION_KEY);

            return redirect()->route('partner.seasons.select')->with('partner_season_notice', __('partner.select_season_to_continue'));
        }

        return $next($request);
    }
}
