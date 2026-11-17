<?php

namespace App\Filament\Support\Actions;

use App\Filament\Support\AdminResourceTable;
use App\Models\PartnerAddress;
use App\Models\PartnerPoolSyncState;
use App\Services\Imports\PartnerPoolAddressOrderTypeUpdater;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateAddressOrderTypesFromPoolAction
{
    public static function make(bool $fromPool = false): BulkAction
    {
        return BulkAction::make('updateOrderTypesFromPool')->label('Rendeléstípusok frissítése a poolból')
            ->icon('heroicon-o-arrow-path')->authorize(fn () => Gate::allows('View:ErpPartnerPool')
                && Gate::allows('Import:PartnerPool') && Gate::allows('viewAny', PartnerAddress::class)
                && Gate::allows('update', new PartnerAddress))
            ->authorizeIndividualRecords(false)->requiresConfirmation()
            ->modalHeading('Kijelölt partnercímek rendeléstípusainak frissítése')
            ->modalDescription('Csak a kijelölt címek engedélyezett rendeléstípusai változnak, partner ERP-kód és címkód alapján. A meglévő típusokat a pool listájára cseréljük, nem hozzáadjuk. Üres javaslat, hiányzó vagy inaktív forrás esetén a címet kihagyjuk. Más címadat és meglévő rendelés nem változik.')
            ->modalSubmitActionLabel('Rendeléstípusok frissítése')->modalCancelActionLabel('Mégse')
            ->fillForm(function ($livewire) use ($fromPool): array {
                $batch = (string) PartnerPoolSyncState::find(1)?->active_batch_id;

                return ['source_batch_id' => $batch, 'mode' => 'all', 'confirmed' => false,
                    'selection_token' => PartnerPoolAddressOrderTypeUpdater::selectionToken(self::selectedIds(null, $livewire), $batch, $fromPool ? 'pool' : 'addresses')];
            })
            ->schema([
                Hidden::make('source_batch_id')->required()->rules(['uuid']),
                Hidden::make('selection_token')->required()->rules(['string', 'size:64']),
                Radio::make('mode')->label('Frissítés módja')->options(PartnerPoolAddressOrderTypeUpdater::modes())->required(),
                Checkbox::make('confirmed')->label('Jóváhagyom a kijelölt címek engedélyezett rendeléstípusainak frissítését.')->accepted()->required(),
            ])
            ->action(function (array $data, $records, BulkAction $action, $livewire) use ($fromPool): void {
                try {
                    $result = app(PartnerPoolAddressOrderTypeUpdater::class)->update(self::selectedIds($records, $livewire),
                        $data['source_batch_id'], $data['mode'], $data['selection_token'], $fromPool ? 'pool' : 'addresses');
                } catch (DomainException|ValidationException $exception) {
                    Notification::make()->danger()->title('A rendeléstípusok nem frissíthetők')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }
                $skipped = $result['missing_source'] + $result['empty_source'] + $result['inactive_source'] + $result['missing_address'];
                $notice = Notification::make()->title('Rendeléstípusok frissítése kész')
                    ->body('Kijelölt sor: '.$result['selected'].'. Frissítve: '.$result['updated'].', változatlan: '.$result['unchanged']
                        .', már kitöltött: '.$result['already_filled'].', nincs poolsor: '.$result['missing_source']
                        .', üres javaslat: '.$result['empty_source'].', inaktív/nem vevői forrás: '.$result['inactive_source']
                        .', nincs a címtörzsben: '.$result['missing_address'].'.');
                $skipped ? $notice->warning() : $notice->success();
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
