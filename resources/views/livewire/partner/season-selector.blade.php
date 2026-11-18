<div class="mx-auto max-w-lg px-4 py-8">
    <form wire:submit="selectSeason" class="space-y-4 rounded-xl border bg-white p-6 shadow-lg">
        <h1 class="text-2xl font-bold">{{ __('partner.change_season') }}</h1>
        <p id="selected-partner-season-help" role="note" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-slate-700">
            <strong class="mb-1 block">{{ __('partner.select_season_to_continue') }}</strong>
            {{ __('partner.change_season_help') }}
        </p>
        @if (session('partner_season_notice'))
            <p role="status" class="text-sm text-amber-800">{{ session('partner_season_notice') }}</p>
        @endif
        <div>
            <label for="selected-partner-season" class="mb-1 block font-medium">{{ __('partner.season') }}</label>
            <select
                id="selected-partner-season"
                wire:model="seasonId"
                required
                title="{{ __('partner.select_season') }}"
                aria-describedby="selected-partner-season-help"
                data-required-message="{{ __('partner.select_season_to_continue') }}"
                oninvalid="this.setCustomValidity(this.value ? '' : this.dataset.requiredMessage)"
                oninput="this.setCustomValidity('')"
                onchange="this.setCustomValidity('')"
                class="w-full rounded border p-2"
            >
                <option value="">{{ __('partner.select_season') }}</option>
                @foreach ($seasons as $season)
                    <option value="{{ $season->id }}">{{ $season->code }} — {{ $season->name }}</option>
                @endforeach
            </select>
            @error('seasonId') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            @if ($seasons->isEmpty()) <p class="mt-1 text-sm text-red-600">{{ __('partner.no_available_seasons') }}</p> @endif
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded bg-slate-900 px-4 py-2 font-semibold text-white disabled:opacity-50">{{ __('partner.use_selected_season') }}</button>
            @if ($hasCurrentSeason)
                <a href="{{ route('partner.orders.select') }}" wire:navigate class="rounded border px-4 py-2">{{ __('partner.cancel_season_change') }}</a>
            @endif
        </div>
    </form>
    <form method="POST" action="{{ route('partner.logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-slate-600 underline">{{ __('partner.logout') }}</button>
    </form>
</div>
