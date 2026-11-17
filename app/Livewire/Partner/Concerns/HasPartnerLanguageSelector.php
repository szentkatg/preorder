<?php

namespace App\Livewire\Partner\Concerns;

use App\Models\Language;
use App\Services\LanguageResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

trait HasPartnerLanguageSelector
{
    public ?int $interfaceLanguageId = null;

    public function mountHasPartnerLanguageSelector(): void
    {
        $this->interfaceLanguageId = app(LanguageResolver::class)->resolve(
            languageId: is_numeric(session('partner.language_id')) ? (int) session('partner.language_id') : null,
            allowedLanguageCodes: $this->interfaceLanguageCodes(),
        )?->id;
    }

    public function getInterfaceLanguagesProperty(): Collection
    {
        return Language::query()->where('active', true)
            ->whereIn('code', $this->interfaceLanguageCodes())->orderBy('id')->get();
    }

    public function updatedInterfaceLanguageId(): void
    {
        $language = $this->interfaceLanguages->firstWhere('id', $this->interfaceLanguageId);
        if (! $language) {
            throw ValidationException::withMessages(['interfaceLanguageId' => __('partner.language_not_available')]);
        }

        $locale = strtolower($language->code);
        session(['partner.locale' => $locale, 'partner.language_id' => (int) $language->id]);
        app()->setLocale($locale);
        Cookie::queue('partner_locale', $locale, 60 * 24 * 365);
        $this->resetValidation('interfaceLanguageId');
        $this->dispatch('partner-interface-language-changed', locale: $locale);
    }

    private function interfaceLanguageCodes(): array
    {
        return array_map('strtoupper', config('app.available_locales', ['hu', 'en']));
    }
}
