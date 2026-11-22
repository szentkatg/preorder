<div class="mx-auto max-w-md px-4 py-8 sm:py-10">
    @if (! $showSeasonModal)
        @include('livewire.partner.auth.partials.language-selector')
        <form wire:submit="login" class="space-y-4 rounded-xl bg-white/90 p-6 shadow-lg backdrop-blur-sm">
            <h1 class="text-2xl font-bold">{{ __('partner.login_title') }}</h1>
            @if (session('partner_season_notice'))
                <p role="status" class="text-sm text-amber-800">{{ session('partner_season_notice') }}</p>
            @endif
            <div>
                <label for="partner-email">{{ __('partner.email') }}</label>
                <input id="partner-email" type="email" wire:model="email" autocomplete="username" required class="w-full rounded border p-2">
                @error('email') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="partner-password">{{ __('partner.password') }}</label>
                <input id="partner-password" type="password" wire:model="password" autocomplete="current-password" required class="w-full rounded border p-2">
                @error('password') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="login" class="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-semibold text-white shadow hover:bg-slate-800 disabled:opacity-50">
                {{ __('partner.login') }}
            </button>
            <div class="text-center">
                <a href="{{ route('partner.password.request') }}" class="text-sm text-blue-600 hover:underline">{{ __('partner.forgot_password') }}</a>
            </div>
        </form>
    @elseif ($showUpdateNotice)
        <style>
            .partner-update-dialog::backdrop { background: rgba(15, 23, 42, .75); }
            .partner-update-dialog { width: min(38rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 0; border: 0; border-radius: 1rem; overflow-y: auto; }
        </style>
        <dialog wire:ignore.self wire:key="partner-login-update-dialog" data-portal-update-notice
            class="partner-update-dialog bg-white text-slate-900 shadow-2xl"
            aria-labelledby="portal-update-title" x-data x-init="$el.showModal()" @cancel.prevent>
            <div class="border-b border-sky-200 bg-sky-50 p-5">
                <h1 id="portal-update-title" class="text-2xl font-bold">{{ __('partner.update_notice_title') }}</h1>
            </div>
            <div class="space-y-4 p-5">
                @include('livewire.partner.auth.partials.language-selector')
                <ul class="list-disc space-y-2 pl-5 text-sm leading-6">
                    <li>{{ __('partner.update_notice_reference') }}</li>
                    <li>{{ __('partner.update_notice_order_type') }}</li>
                    <li>{{ __('partner.update_notice_season') }}</li>
                </ul>
                <p class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm leading-6">{{ __('partner.update_notice_import') }}</p>
                <p class="text-sm">{{ __('partner.update_notice_help') }} <x-partner-help-link /></p>
                <form wire:submit="acknowledgeUpdateNotice" class="space-y-4">
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input type="checkbox" wire:model="doNotShowUpdateNoticeAgain" class="mt-1 h-4 w-4">
                        <span>{{ __('partner.update_notice_do_not_show') }}</span>
                    </label>
                    <button type="submit" wire:loading.attr="disabled" class="w-full rounded-lg bg-sky-700 px-4 py-3 font-semibold text-white disabled:opacity-50">{{ __('partner.update_notice_continue') }}</button>
                    <button type="button" wire:click="cancelLogin" wire:loading.attr="disabled" class="w-full rounded-lg border px-4 py-2 text-sm">{{ __('partner.back_to_login') }}</button>
                </form>
            </div>
        </dialog>
    @else
        <style>
            .partner-season-dialog::backdrop { background: rgba(15, 23, 42, .75); }
            .partner-season-dialog { width: min(30rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 0; border: 0; border-radius: 1rem; overflow-y: auto; }
        </style>
        <dialog wire:ignore.self wire:key="partner-login-season-dialog" class="partner-season-dialog bg-white text-slate-900 shadow-2xl" data-login-season-modal
            aria-labelledby="partner-season-dialog-title" aria-describedby="partner-season-help"
            x-data x-init="$el.showModal()" @cancel.prevent>
            <div class="border-b border-sky-200 bg-sky-50 p-5">
                <h1 id="partner-season-dialog-title" class="text-2xl font-bold">{{ __('partner.login_select_season_title') }}</h1>
                <p id="partner-season-help" role="note" class="mt-2 text-sm text-slate-700">{{ __('partner.login_select_season_help') }}</p>
            </div>
            <div class="p-5">
                <div class="mb-3 flex justify-end"><x-partner-help-link /></div>
                @include('livewire.partner.auth.partials.language-selector')
                <form wire:submit="selectSeason" class="space-y-4">
                    <div>
                        <label for="partner-season" class="mb-2 block font-semibold">{{ __('partner.season') }}</label>
                        <select id="partner-season" wire:model="seasonId" required autofocus
                            title="{{ __('partner.select_season') }}" aria-describedby="partner-season-help"
                            data-required-message="{{ __('partner.select_season_to_continue') }}"
                            oninvalid="this.setCustomValidity(this.value ? '' : this.dataset.requiredMessage)"
                            oninput="this.setCustomValidity('')" onchange="this.setCustomValidity('')"
                            class="w-full rounded-lg border-2 border-sky-500 bg-sky-50 p-3 text-lg">
                            <option value="">{{ __('partner.select_season') }}</option>
                            @foreach ($seasons as $season)
                                <option value="{{ $season->id }}">{{ $season->code }} — {{ $season->name }}</option>
                            @endforeach
                        </select>
                        @error('seasonId') <div role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</div> @enderror
                        @if ($seasons->isEmpty()) <p role="alert" class="mt-2 text-sm text-red-600">{{ __('partner.no_available_seasons') }}</p> @endif
                    </div>
                    <button type="submit" wire:loading.attr="disabled" @disabled($seasons->isEmpty())
                        class="w-full rounded-lg bg-sky-700 px-4 py-3 font-semibold text-white disabled:opacity-50">
                        {{ __('partner.use_selected_season') }}
                    </button>
                    <button type="button" wire:click="cancelLogin" wire:loading.attr="disabled" class="w-full rounded-lg border px-4 py-2 text-sm text-slate-700">
                        {{ __('partner.back_to_login') }}
                    </button>
                </form>
            </div>
        </dialog>
    @endif
</div>
