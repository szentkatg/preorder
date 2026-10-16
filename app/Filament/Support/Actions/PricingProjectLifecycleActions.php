<?php

namespace App\Filament\Support\Actions;

use App\Exports\ErpPricingExport;
use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Models\PricingProject;
use App\Services\Pricing\ErpPricingExportService;
use App\Services\Pricing\PricingProjectPublicationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class PricingProjectLifecycleActions
{
    public static function close(): Action
    {
        return Action::make('publishProject')->label('Lezárás és árak publikálása')->color('success')->requiresConfirmation()
            ->visible(fn (PricingProject $record) => $record->isOpenForPricing() && Gate::allows('publish', $record))
            ->authorize(fn (PricingProject $record) => $record->isOpenForPricing() && Gate::allows('publish', $record))
            ->modalDescription('A jóváhagyott, aktuális árakat a szezon végleges árlistatételeibe írjuk, a meglévő árakat felülírva. Hiba esetén semmi nem változik. Visszanyitás csak külön jogosultsággal lehetséges.')
            ->action(function (PricingProject $record, $livewire): void {
                try {
                    $publication = app(PricingProjectPublicationService::class)->publish($record);
                    Notification::make()->success()->title('Árprojekt lezárva')->body('Publikált árak: '.count($publication->snapshot['prices']).'.')->send();
                    $livewire->redirect(PricingProjectResource::getUrl('view', ['record' => $record]));
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Nem zárható le az árprojekt')->body($exception->getMessage())->send();
                }
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopenProject')->label('Árprojekt visszanyitása')->requiresConfirmation()
            ->visible(fn (PricingProject $record) => $record->status === 'closed' && Gate::allows('reopen', $record))
            ->authorize(fn (PricingProject $record) => $record->status === 'closed' && Gate::allows('reopen', $record))
            ->modalDescription('A publikált árak változatlanok maradnak. A módosítások csak az ismételt lezáráskor kerülnek a végleges árlistákba.')
            ->action(function (PricingProject $record, $livewire): void {
                app(PricingProjectPublicationService::class)->reopen($record);
                Notification::make()->success()->title('Árprojekt visszanyitva')->send();
                $livewire->redirect(PricingProjectResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function export(): Action
    {
        return Action::make('exportErpPrices')->label('ERP árexport (Excel)')->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (PricingProject $record) => Gate::allows('exportErp', $record) && Gate::allows('view', $record) && $record->publications()->exists())
            ->authorize(fn (PricingProject $record) => Gate::allows('exportErp', $record) && Gate::allows('view', $record))
            ->modalDescription('A legutóbb publikált árakból, az ERP fix oszlopsorrendjével. Az Eredeti és Lista ár azonos; nincs átváltás. A nem használt árszintek üresek.')
            ->schema([
                DatePicker::make('start_date')->label('Kezdő dátum')->default(today())->required(),
                ...collect(ErpPricingExportService::SLOTS)->map(fn ($slot, $key) => Select::make('mapping.'.$key)->label($slot[0].' '.$slot[2])
                    ->options(fn (PricingProject $record) => app(ErpPricingExportService::class)->options($record, $key))
                    ->default(function (PricingProject $record) use ($key) {
                        $options = app(ErpPricingExportService::class)->options($record, $key);

                        return count($options) === 1 ? array_key_first($options) : null;
                    })
                    ->required(fn (PricingProject $record) => count(app(ErpPricingExportService::class)->options($record, $key)) > 0)
                    ->searchable())->all(),
            ])
            ->action(function (PricingProject $record, array $data) {
                try {
                    $rows = app(ErpPricingExportService::class)->rows($record, $data['mapping'] ?? [], $data['start_date']);

                    return Excel::download(new ErpPricingExport($rows), 'ERP-arak-'.$record->id.'-'.now()->format('Ymd-His').'.xlsx');
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Nem készíthető ERP export')->body($exception->getMessage())->send();

                    return null;
                }
            });
    }
}
