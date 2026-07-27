<?php

namespace App\Http\Middleware;

use App\Services\LanguageResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetPartnerLocale
{
    public function __construct(
        private readonly LanguageResolver $languageResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $language = $this->languageResolver->resolve(
            allowedLanguageCodes: config('app.available_locales', ['hu', 'en']),
        );

        if ($language !== null) {
            $locale = strtolower((string) $language->code);

            app()->setLocale($locale);

            $request->session()->put([
                'partner.locale' => $locale,
                'partner.language_id' => (int) $language->getKey(),
            ]);

            Cookie::queue('partner_locale', $locale, 60 * 24 * 365);
        } else {
            app()->setLocale('hu');
        }

        return $next($request);
    }
}
