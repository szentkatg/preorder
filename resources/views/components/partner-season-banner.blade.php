@props(['season', 'brand' => null, 'orderSheetType' => null, 'allowSeasonChange' => true])
<div data-partner-season-banner class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-base text-slate-900">
    <span>{{ __('partner.season') }}: <strong>{{ $season->code }} — {{ $season->name }}</strong></span>
    @if ($brand)
        <span>{{ __('partner.brand') }}: <strong>{{ $brand->translate('name') }}</strong></span>
    @endif
    @if ($orderSheetType)
        <span>{{ __('partner.order_sheet_type') }}: <strong>{{ $orderSheetType->translate('name') }}</strong></span>
    @endif
    @if ($allowSeasonChange && auth('partner')->check())
        <a href="{{ route('partner.seasons.select') }}" wire:navigate class="rounded border border-amber-400 bg-white px-3 py-1 text-sm font-semibold text-slate-800 hover:bg-amber-100">{{ __('partner.change_season') }}</a>
    @endif
</div>
