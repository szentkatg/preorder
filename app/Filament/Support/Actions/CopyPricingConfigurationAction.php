<?php

namespace App\Filament\Support\Actions;

use App\Models\PricingParameter;
use App\Services\Pricing\PricingConfigurationCopyService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class CopyPricingConfigurationAction
{
    public static function make(string $model): BulkAction
    {
        $label = $model === PricingParameter::class ? 'Paraméterek másolása' : 'Kerekítési szabályok másolása';

        return BulkAction::make('copyToPricingProject')->label($label)->icon('heroicon-o-document-duplicate')
            ->authorize(fn () => app(PricingConfigurationCopyService::class)->canCopy($model))
            ->modalHeading($label.' másik árprojektbe')->modalSubmitActionLabel('Másolás')->modalCancelActionLabel('Mégse')
            ->modalDescription('Csak a kijelölt sorokat másoljuk. A hivatkozások (főcsoport, modell, árlista, pénznem) változatlanok maradnak; eltérő szezonnál a modellszintű paramétert ellenőrizni kell. Az árak nem számolódnak újra, de a célprojekt meglévő, érinthető árait felülvizsgálatra jelöljük. A publikált árlisták nem változnak.')
            ->schema([
                Select::make('target_project_id')->label('Célárprojekt')->required()->searchable()->preload()->live()
                    ->options(fn ($livewire) => app(PricingConfigurationCopyService::class)->targetOptions($model, $livewire->getSelectedTableRecords()))
                    ->afterStateUpdated(fn (Set $set, $state, $livewire) => self::refreshPreview($model, $state, $set, $livewire))
                    ->hintAction(Action::make('refreshCopyPreview')->label('Előnézet frissítése')->icon('heroicon-o-arrow-path')
                        ->action(fn (Get $get, Set $set, $livewire) => self::refreshPreview($model, $get('target_project_id'), $set, $livewire))),
                Hidden::make('preview_token')->required(),
                Hidden::make('conflict_count')->default(0),
                Hidden::make('can_overwrite')->default(false),
                Hidden::make('preview_summary'),
                Text::make(fn (Get $get) => $get('preview_summary') ?: 'Válassz meglévő, nem lezárt célárprojektet az ütközések ellenőrzéséhez.'),
                Radio::make('conflict_mode')->label('Mi történjen a már létező sorokkal?')
                    ->options(fn (Get $get) => ['skip' => 'Meglévő sorok kihagyása — csak az újak másolása']
                        + ($get('can_overwrite') ? ['overwrite' => 'Meglévő sorok felülírása a kijelölt forrássorok adataival'] : []))
                    ->visible(fn (Get $get) => (int) $get('conflict_count') > 0)
                    ->required(fn (Get $get) => (int) $get('conflict_count') > 0),
            ])
            ->action(function (array $data, $records, BulkAction $action, $livewire) use ($model): void {
                try {
                    $result = app(PricingConfigurationCopyService::class)->copy($model, $records->modelKeys(),
                        (int) $data['target_project_id'], (string) $data['preview_token'], $data['conflict_mode'] ?? null);
                } catch (DomainException $exception) {
                    Notification::make()->warning()->title('A másolás nem végezhető el')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Másolás kész')
                    ->body('Új: '.$result['created'].', felülírt: '.$result['overwritten'].', kihagyott: '.$result['skipped'].
                        ', változatlan: '.$result['unchanged'].', felülvizsgálatra jelölt ársor: '.$result['marked_for_review'].'.')->send();
                $action->success();
                $livewire->deselectAllTableRecords();
            });
    }

    private static function refreshPreview(string $model, mixed $target, Set $set, $livewire): void
    {
        $set('preview_token', null);
        $set('conflict_count', 0);
        $set('conflict_mode', null);
        $set('can_overwrite', false);
        $set('preview_summary', null);
        if (blank($target)) {
            return;
        }
        if (! is_scalar($target) || ! ctype_digit((string) $target) || (int) $target <= 0) {
            $set('preview_summary', 'Válassz érvényes célárprojektet.');

            return;
        }
        try {
            $preview = app(PricingConfigurationCopyService::class)->preview($model, $livewire->getSelectedTableRecords()->modelKeys(), (int) $target);
        } catch (DomainException $exception) {
            $set('preview_summary', $exception->getMessage());

            return;
        }
        $set('preview_token', $preview['token']);
        $set('conflict_count', $preview['conflicts']);
        $set('can_overwrite', $preview['can_overwrite']);
        $set('preview_summary', 'Kijelölt: '.$preview['selected'].' sor. Újként másolható: '.$preview['new'].' sor. Már létező kulccsal ütközik: '.$preview['conflicts'].' sor.'
            .($preview['ambiguous'] ? ' Többszörös céloldali kulcs: '.$preview['ambiguous'].'. Ezek csak kihagyhatók; felülírás előtt javítsd a duplikációt.' : '')
            .($preview['conflicts'] && ! $preview['can_overwrite'] && ! $preview['ambiguous'] ? ' Felülíráshoz módosítási jogosultság szükséges.' : ''));
    }
}
