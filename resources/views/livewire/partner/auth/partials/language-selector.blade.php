<div class="mb-4 flex flex-wrap items-center justify-end gap-2" data-partner-language-tools>
    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
        <span>{{ __('partner.language') }}</span>
        <select wire:model.live="languageId" aria-label="{{ __('partner.language') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 shadow-sm">
            @foreach ($languages as $language)
                <option value="{{ $language->id }}">{{ config('app.locale_flags.'.strtolower($language->code), '🌐') }} {{ strtoupper($language->code) }}</option>
            @endforeach
        </select>
    </label>
    <x-partner-help-link />
</div>
