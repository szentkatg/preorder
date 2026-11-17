<?php

namespace App\Filament\Pages;

use App\Models\PartnerPortalSetting;
use App\Services\Partner\PartnerPortalAvailabilityService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class PartnerPortalAvailability extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-power';

    protected static string|\UnitEnum|null $navigationGroup = 'Rendszerbeállítások';

    protected static ?string $title = 'Partnerportál elérhetősége';

    protected string $view = 'filament.pages.partner-portal-availability';

    public ?array $data = [];

    #[Locked]
    public int $loadedRevision = 0;

    public function mount(): void
    {
        Gate::authorize('View:PartnerPortalAvailability');
        $settings = PartnerPortalSetting::current();
        $this->loadedRevision = $settings->revision;
        $this->form->fill($settings->only(['is_enabled', 'message']));
    }

    public function hydrate(): void
    {
        Gate::authorize('View:PartnerPortalAvailability');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('is_enabled')->label('Partnerportál elérhető')->required()->live()
                ->helperText('Kikapcsolva a bejelentkezett partnerek és a megosztott rendelőlapok is le lesznek zárva. Az admin továbbra is elérhető.'),
            Textarea::make('message')->label('Partneroldali üzenet')->rows(5)->maxLength(4000)
                ->required(fn (Get $get) => ! $get('is_enabled'))
                ->helperText('Lezáráskor ez a szöveg jelenik meg. A sortörések megmaradnak; HTML-formázás nincs. Minden nyelven a megadott szöveg látszik.'),
        ])->statePath('data')->disabled(fn () => ! Gate::allows('Update:PartnerPortalAvailability'));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('saveAvailability')->label('Beállítások mentése')
            ->authorize(fn () => Gate::allows('Update:PartnerPortalAvailability'))
            ->requiresConfirmation()->modalHeading('Partnerportál elérhetőségének módosítása')
            ->modalDescription('Mentés után a beállítás az új megnyitásokra és a már nyitott partneroldalak következő kérésére is érvényes. A folyamatban lévő szerverkéréseket ez nem szakítja meg.')
            ->modalSubmitActionLabel('Mentés')->modalCancelActionLabel('Mégse')
            ->action(function (): void {
                try {
                    $settings = app(PartnerPortalAvailabilityService::class)->update($this->form->getState(), $this->loadedRevision);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('A beállítások nem menthetők')
                        ->body(collect($exception->errors())->flatten()->first())->persistent()->send();
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn ($messages, $field) => [str_starts_with($field, 'data.') ? $field : 'data.'.$field => $messages])->all());
                }
                $this->loadedRevision = $settings->revision;
                $this->form->fill($settings->only(['is_enabled', 'message']));
                Notification::make()->success()->title($settings->is_enabled ? 'Partnerportál elérhető' : 'Partnerportál lezárva')->send();
            })];
    }
}
