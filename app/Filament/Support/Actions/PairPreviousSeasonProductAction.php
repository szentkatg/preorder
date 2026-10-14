<?php

namespace App\Filament\Support\Actions;

use App\Models\PricingCalculationRow;
use App\Models\Product;
use App\Services\Pricing\PreviousSeasonRetailPriceService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;

class PairPreviousSeasonProductAction
{
    public static function make(): Action
    {
        return Action::make('pairPreviousSeasonProduct')->label('Előző szezon párosítása')->icon('heroicon-o-link')
            ->iconButton()->tooltip('Előző szezon párosítása')
            ->modalHeading('Termék párosítása az előző szezonhoz')
            ->modalDescription('A párosítás a termék összes kisker árlistájára érvényes. Automatikus módban az azonos márkához tartozó, szezonvégződés nélkül azonos nevű, egyértelmű találatot használjuk.')
            ->visible(fn (PricingCalculationRow $record): bool => Gate::allows('update', $record) && Gate::allows('update', $record->product))
            ->authorize(fn (PricingCalculationRow $record, $livewire): bool => $livewire->pricingWorkspaceIsEditable()
                && Gate::allows('update', $record) && Gate::allows('update', $record->product))
            ->disabled(fn ($livewire): bool => ! $livewire->pricingWorkspaceIsEditable())
            ->schema([
                Select::make('mode')->label('Párosítás módja')->options(['auto' => 'Automatikus névegyezés', 'manual' => 'Kézi párosítás', 'none' => 'Nincs előző szezonbeli pár'])
                    ->required()->live(),
                Select::make('previous_product_id')->label('Előző szezon terméke')->searchable()
                    ->visible(fn (Get $get): bool => $get('mode') === 'manual')->required(fn (Get $get): bool => $get('mode') === 'manual')
                    ->options(function (PricingCalculationRow $record): array {
                        $previous = app(PreviousSeasonRetailPriceService::class)->previousSeason($record->pricingProject->season);

                        return $previous ? Product::query()->where('season_id', $previous->id)->orderBy('name_hu')->get()
                            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->model_code.' — '.$product->name_hu])->all() : [];
                    }),
            ])->fillForm(fn (PricingCalculationRow $record): array => [
                'mode' => $record->product->previous_season_match_override ? ($record->product->previous_season_product_id ? 'manual' : 'none') : 'auto',
                'previous_product_id' => $record->product->previous_season_product_id,
            ])->action(function (PricingCalculationRow $record, array $data, $livewire): void {
                try {
                    app(PreviousSeasonRetailPriceService::class)->setPair($record->pricingProject, $record->product, $data['mode'], $data['previous_product_id'] ?? null);
                    Notification::make()->title('Párosítás mentve')->success()->send();
                    $livewire->resetTable();
                } catch (DomainException $exception) {
                    Notification::make()->title('A párosítás nem menthető')->body($exception->getMessage())->danger()->send();
                }
            });
    }
}
