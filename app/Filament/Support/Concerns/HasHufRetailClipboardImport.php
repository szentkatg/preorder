<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Pages\PricingHufRetailCalculations;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\HufRetailClipboardImportService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait HasHufRetailClipboardImport
{
    #[Locked]
    public array $hufRetailClipboardResult = [];

    #[Locked]
    public ?string $hufRetailClipboardResultId = null;

    private function hufRetailClipboardCacheKey(): string
    {
        return 'huf-retail-clipboard:'.auth()->id().':'.$this->getId().':'.$this->hufRetailClipboardResultId;
    }

    protected function canImportHufRetailClipboard(): bool
    {
        return static::class === PricingHufRetailCalculations::class && static::canAccess()
            && $this->pricingWorkspaceIsEditable() && Gate::allows('update', new PricingCalculationRow);
    }

    protected function hufRetailClipboardPriceListOptions(): array
    {
        return PriceList::query()->where('active', true)->where('type', 'retail')
            ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
            ->whereIn('id', PricingCalculationRow::query()->select('price_list_id')
                ->where('pricing_project_id', $this->pricingProjectId)->where('price_type', PricingCalculationRow::TYPE_RETAIL))
            ->orderBy('code')->pluck('code', 'id')->all();
    }

    public function importHufRetailClipboardAction(): Action
    {
        return Action::make('importHufRetailClipboard')->label('Manuális árak vágólapról')->icon('heroicon-o-clipboard-document-list')
            ->visible(fn () => $this->canImportHufRetailClipboard())->authorize(fn () => $this->canImportHufRetailClipboard())
            ->modalHeading(fn () => 'Manuális HUF kisker árak — '.$this->pricingSelectedProject?->name)
            ->modalWidth(Width::ThreeExtraLarge)->modalSubmitActionLabel('Árak betöltése')->modalCancelActionLabel('Mégse')
            ->modalDescription('Excelből másold ki a két oszlopot (modellkód, ár), majd Ctrl+V-vel illeszd be. Csak a kiválasztott árprojekt és árlista meglévő kalkulációit módosítjuk, a táblázat szűrésétől és lapozásától függetlenül. Több színszintű kalkulációnál a modell minden színsorát ellenőrizzük.')
            ->schema([
                Select::make('price_list_id')->label('HUF kisker árlista')->required()
                    ->options(fn () => $this->hufRetailClipboardPriceListOptions())
                    ->default(function () {
                        $options = $this->hufRetailClipboardPriceListOptions();

                        return count($options) === 1 ? array_key_first($options) : null;
                    })
                    ->helperText('Csak már kiszámított HUF kisker árlisták választhatók. Ha nincs ilyen, előbb futtasd az újraszámítást.'),
                Textarea::make('clipboard_data')->label('Modellkód és manuális ár')->required()->rows(10)
                    ->maxLength(HufRetailClipboardImportService::MAX_LENGTH)
                    ->placeholder("Modellkód\tÁr\nB10024-W27\t12 990\nB03028 W27\t14990,50")
                    ->helperText('Legfeljebb 2000 adatsor; a fejléc nem kötelező. A modellkódból eltávolítjuk a szóközöket és kötőjeleket. A kalkulált árral egyező sor kimarad (a korábbi manuális ár sem törlődik). Az eltérő ár felülírja a manuális árat, de nem hagyjuk jóvá automatikusan. A függő árakat felülvizsgálatra jelöljük; a publikált árak nem változnak.'),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless($this->canImportHufRetailClipboard(), 403);
                $project = $this->pricingSelectedProject;
                $options = $this->hufRetailClipboardPriceListOptions();
                abort_unless($project && isset($options[$data['price_list_id'] ?? '']), 422);
                try {
                    $result = app(HufRetailClipboardImportService::class)->import(
                        $project, PriceList::findOrFail($data['price_list_id']), $data['clipboard_data'],
                    );
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('A betöltés nem indítható')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }
                if ($this->hufRetailClipboardResultId !== null) {
                    Cache::forget($this->hufRetailClipboardCacheKey());
                }
                $this->hufRetailClipboardResultId = (string) Str::uuid();
                Cache::put($this->hufRetailClipboardCacheKey(), $result, now()->addHour());
                // Keep the full report out of every subsequent Livewire request's snapshot.
                $this->hufRetailClipboardResult = collect($result)->except(['entries', 'row_ids'])->all();
                // Preserve row positions, column preferences and filters; update only affected cells and averages.
                $this->refreshPricingRows(rowIds: $result['row_ids']);
                $this->replaceMountedAction('hufRetailClipboardResult');
                // Replacing a modal at the same nesting level does not queue a modal partial itself.
                app(PartialsComponentHook::class)->renderPartial($this, fn (): array => [
                    'action-modals' => view('filament-actions::components.modals'),
                ]);
            });
    }

    public function hufRetailClipboardResultAction(): Action
    {
        return Action::make('hufRetailClipboardResult')->label('Vágólapos betöltés eredménye')
            ->authorize(fn () => static::class === PricingHufRetailCalculations::class && static::canAccess() && $this->hufRetailClipboardResultId !== null)
            ->modalHeading('Vágólapos árbetöltés eredménye')->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
            ->modalContent(fn () => view('filament.pricing.huf-retail-clipboard-result', [
                'result' => Cache::get($this->hufRetailClipboardCacheKey(), $this->hufRetailClipboardResult + ['expired' => true]),
            ]));
    }
}
