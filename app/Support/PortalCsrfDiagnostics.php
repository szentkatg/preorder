<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalCsrfDiagnostics
{
    public static function record(Request $request): void
    {
        if (! $request->is('livewire/update', 'partner/*', 'admin/*')) {
            return;
        }
        // Diagnostics must never change the original 419 or log request payloads,
        // identities, cookie values, credentials, tokens, IPs or full user agents.
        try {
            $cookieName = (string) config('session.cookie');
            preg_match_all('/(?:^|;\s*)'.preg_quote($cookieName, '/').'=/', (string) $request->header('Cookie'), $matches);
            $agent = (string) $request->userAgent();
            Log::warning('Portal session/CSRF request rejected (419)', [
                'area' => $request->is('livewire/update') ? 'livewire' : ($request->is('admin/*') ? 'admin' : 'partner'),
                'method' => $request->method(), 'https' => $request->isSecure(),
                'session_cookie_entries' => count($matches[0]),
                'session_cookie_readable' => filled($request->cookie($cookieName)),
                'session_started' => $request->hasSession() && $request->session()->isStarted(),
                'csrf_body_present' => filled($request->input('_token')),
                'csrf_header_present' => $request->hasHeader('X-CSRF-TOKEN') || $request->hasHeader('X-XSRF-TOKEN'),
                'authorization_header_present' => $request->hasHeader('Authorization'),
                'mobile_firefox' => stripos($agent, 'Android') !== false && stripos($agent, 'Firefox') !== false,
            ]);
        } catch (\Throwable) {
            // An unavailable logger must not turn an expired session into a 500.
        }
    }
}
