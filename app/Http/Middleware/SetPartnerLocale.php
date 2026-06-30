<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPartnerLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $partnerUser = auth('partner')->user();

        $languageCode = $partnerUser?->partner?->language?->code ?? 'hu';

        app()->setLocale($languageCode);

        return $next($request);
    }
}