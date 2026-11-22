<a href="{{ route('partner.help', ['locale' => app()->getLocale() === 'en' ? 'en' : 'hu']) }}"
    target="_blank" rel="noopener" data-partner-help
    x-data="{ locale: @js(app()->getLocale() === 'en' ? 'en' : 'hu'), urls: @js(['hu' => route('partner.help', ['locale' => 'hu']), 'en' => route('partner.help', ['locale' => 'en'])]) }"
    x-on:partner-interface-language-changed.window="locale = $event.detail.locale === 'en' ? 'en' : 'hu'"
    x-bind:href="urls[locale]" x-text="locale === 'en' ? 'Help' : 'Súgó'"
    {{ $attributes->class(['inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1 text-sm font-semibold text-sky-800 hover:bg-sky-50 focus:ring-2 focus:ring-sky-500']) }}>
    {{ __('partner.help') }}
</a>
