<?php

namespace App\Filament\Support\Actions;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Services\Pricing\HufRetailCalculationService;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class CalculateHufRetailAction
{
    public static function make(?Closure $projectUsing = null): Action
    {
        return Action::make('calculateHufRetail')
            ->label('HUF kisker árak újraszámítása')->icon('heroicon-o-calculator')->color('success')
            ->visible(fn (): bool => Gate::allows('create', PricingCalculationRow::class)
                && Gate::allows('update', new PricingCalculationRow)
                && (! $projectUsing || Gate::allows('update', $projectUsing())))
            ->modalHeading('HUF bruttó kisker árak számítása')
            ->modalDescription('A mentett végleges bekerülési értékből számolunk, a Kisker szorzó és a kisker kerekítési szabály alapján. A jóváhagyott és manuális árakat megtartjuk; eltérésnél felülvizsgálatot jelzünk.')
            ->schema([
                Select::make('pricing_project_id')->label('Árprojekt')
                    ->options(fn (): array => PricingProject::query()->where('active', true)
                        ->where('status', '!=', PricingProject::STATUS_CLOSED)->pluck('name', 'id')->all())
                    ->visible(! $projectUsing)->required(! $projectUsing)->searchable()->live()
                    ->afterStateUpdated(fn ($set) => $set('price_list_id', null)),
                Select::make('price_list_id')->label('HUF kisker árlista')
                    ->placeholder('Összes aktív HUF kisker árlista')
                    ->options(function ($get) use ($projectUsing): array {
                        $project = $projectUsing ? $projectUsing() : PricingProject::find($get('pricing_project_id'));
                        if (! $project) {
                            return [];
                        }

                        return PriceList::query()->where('active', true)->where('type', 'retail')
                            ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
                            ->where(fn ($query) => $query->whereNull('season_id')->orWhere('season_id', $project->season_id))
                            ->orderBy('code')->get()->mapWithKeys(fn ($list) => [$list->id => $list->code.' – '.$list->name_hu])->all();
                    })->searchable(),
            ])
            ->action(function (array $data, $livewire) use ($projectUsing): void {
                $project = $projectUsing ? $projectUsing() : PricingProject::findOrFail($data['pricing_project_id']);
                Gate::authorize('update', $project);
                Gate::authorize('create', PricingCalculationRow::class);
                Gate::authorize('update', new PricingCalculationRow);

                try {
                    $result = app(HufRetailCalculationService::class)->recalculate(
                        $project,
                        filled($data['price_list_id'] ?? null) ? PriceList::findOrFail($data['price_list_id']) : null,
                    );
                    $notification = Notification::make()->title('HUF kisker kalkuláció kész')
                        ->body('Létrehozva: '.$result['created'].', frissítve: '.$result['updated']
                            .', felülvizsgálandó: '.$result['requires_review'].', kihagyott: '.$result['skipped']
                            .'. Hibák a Kalkulációs hibák menüben. Futás: '.$result['run_id']);
                    ($result['skipped'] > 0 ? $notification->warning() : $notification->success())->send();

                    if (method_exists($livewire, 'resetTable')) {
                        $livewire->resetTable();
                    }
                } catch (DomainException $exception) {
                    Notification::make()->title('A kisker kalkuláció nem indítható')
                        ->body($exception->getMessage())->danger()->send();
                }
            });
    }
}
