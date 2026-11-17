<?php

namespace App\Http\Middleware;

use App\Models\PartnerPortalSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerPortalIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        // Read on each request, without a cross-request cache: reopening is immediate.
        $settings = PartnerPortalSetting::current();
        if (! $settings->is_enabled) {
            $locale = $request->session()->get('partner.locale');
            if (is_string($locale) && in_array($locale, config('app.available_locales', ['hu', 'en']), true)) {
                app()->setLocale($locale);
            }
            // Persistent Livewire middleware ignores ordinary non-redirect responses.
            // Abort with the response so no component action can run while closed.
            abort(response()->view('partner.unavailable', ['message' => $settings->message], 503, [
                'Cache-Control' => 'no-store, private, max-age=0', 'Retry-After' => '60', 'X-Robots-Tag' => 'noindex, nofollow',
            ]));
        }

        return $next($request);
    }
}
