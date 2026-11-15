<?php

namespace App\Filament\Support\Actions;

use App\Filament\Pages\ErpPartnerPool;
use App\Filament\Support\AdminResourceTable;
use App\Models\Partner;
use App\Models\PartnerPoolSyncState;
use App\Services\Imports\PartnerPoolSalesRepresentativeUpdater;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdatePartnerPoolSalesRepresentativesAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('updateSalesRepresentatives')->label('Területi képviselők frissítése a poolból')
            ->icon('heroicon-o-arrow-path')->authorize(fn (): bool => ErpPartnerPool::canAccess()
                && Gate::allows('Import:PartnerPool') && Gate::allows('update', new Partner))
            ->authorizeIndividualRecords(false)->requiresConfirmation()
            ->modalHeading('Kijelölt partnerek területi képviselőinek frissítése')
            ->modalSubmitActionLabel('Képviselők frissítése')->modalCancelActionLabel('Mégse')
            ->modalDescription('Kizárólag a kijelölt poolsorokhoz tartozó, már létező partnerek képviselőmezője változik. Több cím kijelölésekor egy partnert egyszer frissítünk. Új partner nem készül. Az üres ERP-képviselő nem törli a meglévő értéket. A képviselő módosítása az automatikus partnerportál-hozzáférést is megváltoztathatja.')
            ->fillForm(function ($livewire): array {
                $batch = (string) PartnerPoolSyncState::find(1)?->active_batch_id;

                return ['source_batch_id' => $batch, 'mode' => PartnerPoolSalesRepresentativeUpdater::MODE_EMPTY,
                    'selection_token' => PartnerPoolSalesRepresentativeUpdater::selectionToken(self::selectedIds(null, $livewire), $batch),
                    'confirmed' => false];
            })
            ->schema([
                Hidden::make('source_batch_id')->required()->rules(['uuid']),
                Hidden::make('selection_token')->required()->rules(['string', 'size:64']),
                Radio::make('mode')->label('Frissítés módja')->options(PartnerPoolSalesRepresentativeUpdater::modes())->required(),
                Checkbox::make('confirmed')->label('Jóváhagyom a kijelölt partnerek képviselőadatainak frissítését.')->accepted()->required(),
            ])
            ->action(function (array $data, $records, BulkAction $action, $livewire): void {
                try {
                    $result = app(PartnerPoolSalesRepresentativeUpdater::class)->update(self::selectedIds($records, $livewire),
                        $data['source_batch_id'], $data['mode'], $data['selection_token']);
                } catch (DomainException|ValidationException $exception) {
                    Notification::make()->danger()->title('A képviselők nem frissíthetők')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }
                $notice = Notification::make()->title('Területi képviselők frissítése kész')
                    ->body('Kijelölt címsor: '.$result['selected_rows'].', partner: '.$result['selected_partners']
                        .'. Frissítve: '.$result['updated'].', változatlan: '.$result['unchanged']
                        .', már kitöltött (kihagyva): '.$result['already_filled'].', nincs a partnertörzsben: '.$result['missing_partner']
                        .', üres képviselő a poolban: '.$result['missing_representative'].'.');
                if ($result['missing_partner'] || $result['missing_representative']) {
                    $notice->warning();
                } else {
                    $notice->success();
                }
                $notice->send();
                $livewire->deselectAllTableRecords();
                AdminResourceTable::refreshPreservingState($livewire);
            });
    }

    private static function selectedIds($records, $livewire): array
    {
        return $livewire->isTrackingDeselectedTableRecords
            ? ($records ?? $livewire->getSelectedTableRecords())->modelKeys() : $livewire->selectedTableRecords;
    }
}
