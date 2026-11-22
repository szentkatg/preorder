<?php

namespace App\Livewire\Partner\Auth;

use App\Models\Language;
use App\Models\PartnerUser;
use App\Models\Season;
use App\Services\LanguageResolver;
use App\Services\Partner\PartnerPortalNotice;
use App\Services\Partner\PartnerSeasonContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Login extends Component
{
    public ?int $languageId = null;

    public ?int $seasonId = null;

    public string $email = '';

    public string $password = '';

    #[Locked]
    public bool $showSeasonModal = false;

    #[Locked]
    public ?int $pendingPartnerUserId = null;

    #[Locked]
    public bool $showUpdateNotice = false;

    public bool $doNotShowUpdateNoticeAgain = false;

    public function mount(LanguageResolver $languageResolver): void
    {
        $language = $languageResolver->resolve(
            allowedLanguageCodes: $this->availableLocaleCodes(),
        );

        $this->applyLanguage($language);
        $user = PartnerUser::find(Auth::guard('partner')->id());
        if ($user?->active) {
            $this->pendingPartnerUserId = (int) $user->id;
            $this->showSeasonModal = true;
            $this->showUpdateNotice = app(PartnerPortalNotice::class)->shouldShow($user);
            $seasons = Season::query()->visibleToPartners()->get();
            $this->seasonId = PartnerSeasonContext::current()?->id
                ?? ($seasons->count() === 1 ? (int) $seasons->first()->id : null);
        } elseif ($user) {
            Auth::guard('partner')->logout();
        }
    }

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function hydrate(): void
    {
        if ($this->showSeasonModal) {
            $this->assertPendingPartner();
        }
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
        abort_if($this->showSeasonModal, 403);
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('partner')->attempt([
            'email' => $this->email,
            'password' => $this->password,
            'active' => true,
        ])) {
            $this->password = '';
            $this->addError(
                'email',
                __('partner.invalid_credentials')
            );

            return;
        }

        $this->password = '';
        $this->seasonId = null;
        session()->forget([PartnerSeasonContext::SESSION_KEY, 'partner_order_selector', 'order_selector']);
        // SessionGuard rotates the session ID and CSRF token on authentication.
        // A full GET gives the modal a fresh token, not the old Livewire document.
        $this->redirectRoute('partner.login');
    }

    public function selectSeason(): void
    {
        $this->assertPendingPartner();
        $this->validate([
            'seasonId' => ['required', 'integer', Rule::exists('seasons', 'id')->where('visible_to_partners', true)],
        ], ['seasonId.required' => __('partner.select_season_to_continue'), 'seasonId.exists' => __('partner.select_season_to_continue')]);
        PartnerSeasonContext::select($this->seasonId);
        session()->regenerate();
        $this->redirectRoute('partner.orders.select');
    }

    public function acknowledgeUpdateNotice(): void
    {
        $this->assertPendingPartner();
        abort_unless($this->showUpdateNotice, 403);
        if ($this->doNotShowUpdateNoticeAgain) {
            app(PartnerPortalNotice::class)->dismiss(PartnerUser::findOrFail(Auth::guard('partner')->id()));
        }
        $this->showUpdateNotice = false;
    }

    public function cancelLogin(): void
    {
        $this->assertPendingPartner();
        Auth::guard('partner')->logout();
        session()->forget([PartnerSeasonContext::SESSION_KEY, 'partner_order_selector', 'order_selector']);
        session()->regenerate(true);
        $this->password = '';
        $this->redirectRoute('partner.login');
    }

    private function assertPendingPartner(): void
    {
        $user = PartnerUser::find(Auth::guard('partner')->id());
        abort_unless($this->showSeasonModal && $user?->active
            && (int) $user->id === $this->pendingPartnerUserId, 403);
    }

    public function render()
    {
        return view('livewire.partner.auth.login', [
            'languages' => Language::query()
                ->where('active', true)
                ->whereIn('code', $this->availableLocaleCodes())
                ->orderBy('id')
                ->get(),
            'seasons' => $this->showSeasonModal ? Season::query()->visibleToPartners()->orderByDesc('id')->get() : collect(),
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
        $this->dispatch('partner-interface-language-changed', locale: $locale);
    }
}
