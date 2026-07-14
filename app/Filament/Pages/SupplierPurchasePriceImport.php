<?php

namespace App\Filament\Pages;

use App\Services\Imports\SupplierPurchasePriceImportService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class SupplierPurchasePriceImport extends Page implements
    Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-arrow-up-tray';

    protected static string|\UnitEnum|null $navigationGroup =
        'Import';

    protected static ?string $navigationLabel =
        'Beszállító és beszerzési ár import';

    protected static ?string $title =
        'Beszállító és beszerzési ár import';

    protected static ?int $navigationSort = 4;

    protected string $view =
        'filament.pages.supplier-purchase-price-import';

    public ?array $data = [];

    public array $fatalValidationErrors = [];

    public array $rowValidationErrors = [];

    public array $statistics = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\FileUpload::make('file')
                    ->label('Excel fájl')
                    ->helperText(
                        'A fájl tartalmazhat suppliers és/vagy '
                        . 'product_purchase_prices munkalapot.'
                    )
                    ->storeFiles(false)
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->required(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validálás')
                ->icon('heroicon-o-check-circle')
                ->action('validateImport'),

            Action::make('import')
                ->label('Importálás')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Importálás indítása')
                ->modalDescription(
                    'A beszállítók és beszerzési árak létrehozása '
                    . 'vagy frissítése megtörténik.'
                )
                ->action('import'),
        ];
    }

    public function validateImport(
        SupplierPurchasePriceImportService $service
    ): void {
        $this->resetImportState();

        $result = $service->validate(
            $this->data['file'] ?? null
        );

        $this->applyResult($result);
    }

    public function import(
        SupplierPurchasePriceImportService $service
    ): void {
        $this->resetImportState();

        $result = $service->import(
            $this->data['file'] ?? null
        );

        $this->applyResult($result);
    }

    protected function resetImportState(): void
    {
        $this->fatalValidationErrors = [];
        $this->rowValidationErrors = [];
        $this->statistics = [];
    }

    /**
     * @param array{
     *     fatalErrors?: array<int, string>,
     *     rowErrors?: array<int, string>,
     *     statistics?: array<string, mixed>
     * } $result
     */
    protected function applyResult(array $result): void
    {
        $this->fatalValidationErrors =
            $result['fatalErrors'] ?? [];

        $this->rowValidationErrors =
            $result['rowErrors'] ?? [];

        $this->statistics =
            $result['statistics'] ?? [];
    }
}