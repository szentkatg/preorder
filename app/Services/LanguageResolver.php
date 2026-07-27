<?php

namespace App\Services;

use App\Models\Language;
use Illuminate\Http\Request;

class LanguageResolver
{
    public const DEFAULT_LANGUAGE_CODE = 'HU';

    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * @param  array<int, string>|null  $allowedLanguageCodes
     */
    public function resolve(
        ?int $languageId = null,
        ?array $allowedLanguageCodes = null,
    ): ?Language {
        $allowedLanguageCodes = $this->normalizeAllowedLanguageCodes(
            $allowedLanguageCodes
        );

        if ($languageId !== null) {
            $language = $this->findActiveLanguageById($languageId);

            if ($this->isAllowed($language, $allowedLanguageCodes)) {
                return $language;
            }
        }

        $sessionLanguage = $this->resolveFromSession();

        if ($this->isAllowed($sessionLanguage, $allowedLanguageCodes)) {
            return $sessionLanguage;
        }

        $cookieLanguage = $this->resolveFromCookie();

        if ($this->isAllowed($cookieLanguage, $allowedLanguageCodes)) {
            return $cookieLanguage;
        }

        /*
         * A bejelentkezett felhasználó language_id értékének kezelése
         * később, az egységes IAM rendszer bevezetésekor kerül ide.
         */

        $browserLanguage = $this->resolveFromBrowser($allowedLanguageCodes);

        if ($browserLanguage !== null) {
            return $browserLanguage;
        }

        return $this->resolveDefaultLanguage($allowedLanguageCodes);
    }

    private function resolveFromSession(): ?Language
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $languageId = $this->request->session()->get('partner.language_id')
            ?? $this->request->session()->get('language_id');

        if (! is_numeric($languageId)) {
            return null;
        }

        return $this->findActiveLanguageById((int) $languageId);
    }

    private function resolveFromCookie(): ?Language
    {
        $languageCode = $this->normalizeLanguageCode(
            (string) $this->request->cookie('partner_locale', '')
        );

        return $languageCode !== null
            ? $this->findActiveLanguageByCode($languageCode)
            : null;
    }

    /**
     * @param  array<int, string>|null  $allowedLanguageCodes
     */
    private function resolveFromBrowser(?array $allowedLanguageCodes): ?Language
    {
        foreach ($this->request->getLanguages() as $locale) {
            $languageCode = $this->normalizeLanguageCode($locale);

            if (
                $languageCode === null
                || ($allowedLanguageCodes !== null
                    && ! in_array($languageCode, $allowedLanguageCodes, true))
            ) {
                continue;
            }

            $language = $this->findActiveLanguageByCode($languageCode);

            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>|null  $allowedLanguageCodes
     */
    private function resolveDefaultLanguage(?array $allowedLanguageCodes): ?Language
    {
        $defaultLanguage = $this->findActiveLanguageByCode(
            self::DEFAULT_LANGUAGE_CODE
        );

        if ($this->isAllowed($defaultLanguage, $allowedLanguageCodes)) {
            return $defaultLanguage;
        }

        return $this->findFirstActiveLanguage($allowedLanguageCodes);
    }

    protected function findActiveLanguageById(int $languageId): ?Language
    {
        return Language::query()
            ->whereKey($languageId)
            ->where('active', true)
            ->first();
    }

    protected function findActiveLanguageByCode(string $languageCode): ?Language
    {
        return Language::query()
            ->where('code', $languageCode)
            ->where('active', true)
            ->first();
    }

    /**
     * @param  array<int, string>|null  $allowedLanguageCodes
     */
    protected function findFirstActiveLanguage(
        ?array $allowedLanguageCodes,
    ): ?Language {
        return Language::query()
            ->where('active', true)
            ->when(
                $allowedLanguageCodes !== null,
                fn ($query) => $query->whereIn('code', $allowedLanguageCodes),
            )
            ->orderBy('id')
            ->first();
    }

    private function normalizeLanguageCode(string $locale): ?string
    {
        $languageCode = strtoupper(
            substr(str_replace('_', '-', trim($locale)), 0, 2)
        );

        return strlen($languageCode) === 2
            ? $languageCode
            : null;
    }

    /**
     * @param  array<int, string>|null  $languageCodes
     * @return array<int, string>|null
     */
    private function normalizeAllowedLanguageCodes(?array $languageCodes): ?array
    {
        if ($languageCodes === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $code): ?string => $this->normalizeLanguageCode($code),
            $languageCodes,
        ))));
    }

    /**
     * @param  array<int, string>|null  $allowedLanguageCodes
     */
    private function isAllowed(
        ?Language $language,
        ?array $allowedLanguageCodes,
    ): bool {
        if ($language === null) {
            return false;
        }

        return $allowedLanguageCodes === null
            || in_array(
                strtoupper((string) $language->code),
                $allowedLanguageCodes,
                true,
            );
    }
}
