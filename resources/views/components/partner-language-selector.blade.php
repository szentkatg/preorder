<div x-data data-partner-language-tools @partner-interface-language-changed.window="document.documentElement.lang = $event.detail.locale">
    <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-2 whitespace-nowrap text-sm font-medium text-slate-700">
            <span>{{ __('partner.language') }}</span>
            <select
                wire:model.live="interfaceLanguageId"
                wire:loading.attr="disabled"
                wire:target="interfaceLanguageId"
                aria-label="{{ __('partner.language') }}"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 shadow-sm"
            >
                @foreach ($this->interfaceLanguages as $language)
                    <option value="{{ $language->id }}">
                        {{ config('app.locale_flags.'.strtolower($language->code), '🌐') }} {{ strtoupper($language->code) }}
                    </option>
                @endforeach
            </select>
        </label>
        <x-partner-help-link />
    </div>
    @error('interfaceLanguageId') <p role="alert" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
