<?php

namespace App\Filament\Support\Actions;

use App\Exports\ErpPricingExport;
use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\Schemas\ErpPricingExportForm;
use App\Models\PricingProject;
use App\Services\Pricing\ErpPricingExportService;
use App\Services\Pricing\PricingProjectPublicationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
            ->modalWidth(Width::SevenExtraLarge)
            ->modalDescription('A legutóbb publikált árakból, fix 30 oszloppal. Forrásként árlista vagy kerekíthető képlet választható. Az Eredeti és Lista ár azonos; az árlistákat nem módosítjuk. A beállításokat felhasználónként, árprojektenként megjegyezzük, a Mégsem gombbal bezáráskor is. A kezdő dátum mindig a mai nap.')
            ->schema(fn (PricingProject $record): array => ErpPricingExportForm::make($record))
            ->action(function (PricingProject $record, array $data, Action $action) {
                try {
                    $rows = app(ErpPricingExportService::class)->rows($record, $data['mapping'] ?? [], $data['start_date'], $data['formulas'] ?? [], $data['rounding'] ?? []);

                    return Excel::download(new ErpPricingExport($rows), 'ERP-arak-'.$record->id.'-'.now()->format('Ymd-His').'.xlsx');
                } catch (ValidationException $exception) {
                    $prefix = 'mountedActions.'.$action->getNestingIndex().'.data.';
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn ($messages, $field) => [$prefix.$field => $messages])->all());
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Nem készíthető ERP export')->body($exception->getMessage())->send();

                    return null;
                }
            });
    }
}
