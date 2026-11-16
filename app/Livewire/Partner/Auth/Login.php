<?php

namespace App\Livewire\Partner\Auth;

use App\Models\Language;
use App\Models\Season;
use App\Services\LanguageResolver;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Login extends Component
{
    public ?int $languageId = null;

    public ?int $seasonId = null;

    public string $email = '';

    public string $password = '';

    public function mount(LanguageResolver $languageResolver): void
    {
        $language = $languageResolver->resolve(
            allowedLanguageCodes: $this->availableLocaleCodes(),
        );

        $this->applyLanguage($language);
        $seasons = Season::query()->visibleToPartners()->get();
        $this->seasonId = $seasons->count() === 1 ? (int) $seasons->first()->id : null;
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
            'seasonId' => ['required', 'integer', Rule::exists('seasons', 'id')->where('visible_to_partners', true)],
        ], ['seasonId.required' => __('partner.select_season_to_continue'), 'seasonId.exists' => __('partner.select_season_to_continue')]);

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

        PartnerSeasonContext::select($this->seasonId);
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
            'seasons' => Season::query()->visibleToPartners()->orderByDesc('id')->get(),
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
