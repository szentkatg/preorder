<?php

namespace App\Services;

use App\Models\Language;
use Illuminate\Http\Request;

class LanguageResolver
{
    public const DEFAULT_LANGUAGE_CODE = 'HU';

    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function resolve(?int $languageId = null): ?Language
    {
        if ($languageId !== null) {
            $language = $this->findActiveLanguageById($languageId);

            if ($language !== null) {
                return $language;
            }
        }

        $sessionLanguage = $this->resolveFromSession();

        if ($sessionLanguage !== null) {
            return $sessionLanguage;
        }

        /*
         * A bejelentkezett felhasználó language_id értékének kezelése
         * később, az egységes IAM rendszer bevezetésekor kerül ide.
         */

        $browserLanguage = $this->resolveFromBrowser();

        if ($browserLanguage !== null) {
            return $browserLanguage;
        }

        return $this->resolveDefaultLanguage();
    }

    private function resolveFromSession(): ?Language
    {
        if (! $this->request->hasSession()) {
            return null;
        }

        $languageId = $this->request->session()->get('language_id');

        if (! is_numeric($languageId)) {
            return null;
        }

        return $this->findActiveLanguageById((int) $languageId);
    }

    private function resolveFromBrowser(): ?Language
    {
        foreach ($this->request->getLanguages() as $locale) {
            $languageCode = $this->normalizeLanguageCode($locale);

            if ($languageCode === null) {
                continue;
            }

            $language = Language::query()
                ->where('code', $languageCode)
                ->where('active', true)
                ->first();

            if ($language !== null) {
                return $language;
            }
        }

        return null;
    }

    private function resolveDefaultLanguage(): ?Language
    {
        return Language::query()
            ->where('code', self::DEFAULT_LANGUAGE_CODE)
            ->where('active', true)
            ->first()
            ?? Language::query()
                ->where('active', true)
                ->orderBy('id')
                ->first();
    }

    private function findActiveLanguageById(int $languageId): ?Language
    {
        return Language::query()
            ->whereKey($languageId)
            ->where('active', true)
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
}