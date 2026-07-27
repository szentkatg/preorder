<?php

namespace App\Livewire\Partner\Auth;

use App\Models\Language;
use App\Services\LanguageResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Component;

class Login extends Component
{
    public ?int $languageId = null;

    public string $email = '';

    public string $password = '';

    public function mount(LanguageResolver $languageResolver): void
    {
        $language = $languageResolver->resolve(
            allowedLanguageCodes: $this->availableLocaleCodes(),
        );

        $this->applyLanguage($language);
    }

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function updatedLanguageId(
        mixed $languageId,
        LanguageResolver $languageResolver,
    ): void {
        $language = $languageResolver->resolve(
            languageId: is_numeric($languageId) ? (int) $languageId : null,
            allowedLanguageCodes: $this->availableLocaleCodes(),
        );

        $this->applyLanguage($language);
    }

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('partner')->attempt([
            'email' => $this->email,
            'password' => $this->password,
            'active' => true,
        ])) {
            $this->addError(
                'email',
                __('partner.invalid_credentials')
            );

            return;
        }

        session()->forget([
            'partner_order_selector.season_id',
            'partner_order_selector.brand_id',
            'partner_order_selector.partner_address_id',
            'partner_order_selector.order_sheet_type_id',
            'partner_order_selector.order_id',
        ]);
        session()->regenerate();

        $this->redirectRoute(
            'partner.orders.select',
            navigate: true
        );
    }

    public function render()
    {
        return view('livewire.partner.auth.login', [
            'languages' => Language::query()
                ->where('active', true)
                ->whereIn('code', $this->availableLocaleCodes())
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function availableLocaleCodes(): array
    {
        return array_map(
            'strtoupper',
            config('app.available_locales', ['hu', 'en']),
        );
    }

    private function applyLanguage(?Language $language): void
    {
        if ($language === null) {
            $this->languageId = null;
            app()->setLocale('hu');

            return;
        }

        $this->languageId = (int) $language->getKey();
        $locale = strtolower((string) $language->code);

        app()->setLocale($locale);

        session([
            'partner.locale' => $locale,
            'partner.language_id' => $this->languageId,
        ]);

        Cookie::queue('partner_locale', $locale, 60 * 24 * 365);
    }
}
