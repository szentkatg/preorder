<?php

namespace App\Filament\Resources\SkuReplacements\Pages;

use App\Exports\SkuReplacementImportTemplate;
use App\Filament\Resources\SkuReplacements\SkuReplacementResource;
use App\Models\SkuReplacement;
use App\Services\Imports\SkuReplacementImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Maatwebsite\Excel\Facades\Excel;

class ImportSkuReplacements extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = SkuReplacementResource::class;

    protected static ?string $title = 'Cikkcserék importálása Excelből';

    protected string $view = 'filament.pages.sku-replacement-import';

    public ?array $data = [];

    public array $preview = [];

    #[Locked]
    public ?string $previewToken = null;

    public static function authorizeResourceAccess(): void
    {
        abort_unless(SkuReplacementResource::canViewAny() && SkuReplacementResource::canCreate(), 403);
    }

    public function mount(): void
    {
        $this->form->fill(['existing_mode' => 'skip']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('file')->label('Excel-fájl')->required()->storeFiles(false)->maxSize(10240)
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                ->helperText('Az első munkalap első sora: season_code, old_sku_code, new_sku_code. A cikkszámok szöveges cellák legyenek. Legfeljebb 10 000 adatsor.'),
            Radio::make('existing_mode')->label('Mi történjen a már létező cserékkel?')->required()->default('skip')->live()
                ->options(fn (): array => ['skip' => SkuReplacementImportService::modes()['skip']]
                    + (Gate::allows('update', new SkuReplacement) ? ['overwrite' => SkuReplacementImportService::modes()['overwrite']] : [])),
        ])->statePath('data');
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data.')) {
            $this->previewToken = null;
            $this->preview = [];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')->label('Excel-minta letöltése')->icon('heroicon-o-document-arrow-down')
                ->action(fn () => Excel::download(new SkuReplacementImportTemplate, 'cikkcsere-import-minta.xlsx')),
            Action::make('validateImport')->label('Ellenőrzés és előnézet')->icon('heroicon-o-check-circle')->action(fn () => $this->validateImport()),
            Action::make('import')->label('Importálás')->color('success')->icon('heroicon-o-arrow-up-tray')
                ->disabled(fn (): bool => $this->previewToken === null)
                ->requiresConfirmation()->modalHeading('Cikkcserék importálása')
                ->modalDescription('A hibátlanul ellenőrzött csomagot egyben mentjük. Csak a cikkcsere-törzs változik, a rendeléseket nem írjuk át.')
                ->action(fn () => $this->import()),
            Action::make('back')->label('Vissza a cikkcserékhez')->url(SkuReplacementResource::getUrl()),
        ];
    }

    public function validateImport(): void
    {
        $this->previewToken = null;
        $this->preview = [];
        $data = $this->form->getState();
        $result = app(SkuReplacementImportService::class)->preview($data['file'], $data['existing_mode']);
        $this->previewToken = $result['token'];
        $this->preview = ['counts' => $result['counts'], 'total' => count($result['rows']),
            'rows' => array_slice($result['rows'], 0, 100), 'errors' => array_slice($result['errors'], 0, 100), 'error_count' => count($result['errors'])];
    }

    public function import(): void
    {
        if ($this->previewToken === null) {
            throw ValidationException::withMessages(['data.file' => 'Importálás előtt ellenőrizd az Excel-fájlt.']);
        }
        $data = $this->form->getState();
        try {
            $counts = app(SkuReplacementImportService::class)->import($data['file'], $data['existing_mode'], $this->previewToken);
        } catch (ValidationException $error) {
            $this->previewToken = null;
            throw $error;
        }
        $this->previewToken = null;
        Notification::make()->success()->title('Cikkcserék importálása kész')
            ->body('Új: '.$counts['create'].', felülírt: '.$counts['overwrite'].', kihagyott: '.$counts['skip'].', változatlan: '.$counts['unchanged'].'.')->send();
    }
}
