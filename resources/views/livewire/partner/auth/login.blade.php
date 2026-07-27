<div class="mx-auto max-w-md px-4 py-8 sm:py-10">
    <div class="mb-4 flex justify-end">
        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <span>{{ __('partner.language') }}</span>
            <select
                wire:model.live="languageId"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                aria-label="{{ __('partner.language') }}"
            >
                @foreach ($languages as $language)
                    <option value="{{ $language->id }}">
                        {{ $language->translate('name', $languageId) }}
                    </option>
                @endforeach
            </select>
        </label>
    </div>

    <form wire:submit="login" class="space-y-4 rounded-xl bg-white/90 p-6 shadow-lg backdrop-blur-sm">
        <h1 class="text-2xl font-bold">{{ __('partner.login_title') }}</h1>

        <div>
            <label for="partner-email">{{ __('partner.email') }}</label>
            <input
                id="partner-email"
                type="email"
                wire:model="email"
                class="w-full rounded border p-2"
            >

            @error('email')
                <div class="text-sm text-red-600">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="partner-password">{{ __('partner.password') }}</label>
            <input
                id="partner-password"
                type="password"
                wire:model="password"
                class="w-full rounded border p-2"
            >

            @error('password')
                <div class="text-sm text-red-600">{{ $message }}</div>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-semibold text-white shadow hover:bg-slate-800"
        >
            {{ __('partner.login') }}
        </button>

        <div class="text-center">
            <a
                href="{{ route('partner.password.request') }}"
                class="text-sm text-blue-600 hover:underline"
            >
                {{ __('partner.forgot_password') }}
            </a>
        </div>
    </form>
</div>
