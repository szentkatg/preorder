<?php

namespace App\Filament\Support\Actions;

use App\Models\Product;
use App\Services\ProductBulkUpdateService;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BulkUpdateProductsAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulkUpdateProducts')->label('Termékek tömeges módosítása')->icon('heroicon-o-pencil-square')
            ->authorize(fn () => Gate::allows('viewAny', Product::class) && Gate::allows('update', new Product))
            ->authorizeIndividualRecords(false)
            ->modalHeading('Kijelölt termékek tömeges módosítása')->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Igen, módosítom')->modalCancelActionLabel('Mégse')
            ->modalDescription('Csak a megadott mezőket írjuk felül minden kijelölt terméken. Opcionális mezőnél az üres érték törlést jelent. Modellkód/méretsor átírásakor az SKU-szinkron külön indítható. Főcsoport vagy anyagösszetétel változásakor a nyitott projektek kapcsolódó árai felülvizsgálandók lesznek; a publikált árakat nem módosítjuk.')
            ->steps([
                Step::make('Módosítások')->schema([
                    Hidden::make('selection_token')->default(fn ($livewire) => ProductBulkUpdateService::selectionToken(self::selectedIds(null, $livewire)))
                        ->required()->rules(['string', 'size:64']),
                    Repeater::make('changes')->label('Felülírandó mezők')->minItems(1)->maxItems(count(ProductBulkUpdateService::fields()))
                        ->defaultItems(1)->reorderable(false)->addActionLabel('További mező')->columns(2)
                        ->schema([
                            Select::make('field')->label('Mező')->required()->live()->searchable()
                                ->options(collect(ProductBulkUpdateService::fields())->mapWithKeys(fn ($field, $key) => [$key => $field['label']])->all())
                                ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(function (Select $component, Set $set): void {
                                    $set('value', null);
                                    $component->getContainer()->getComponent('bulkProductValue')->getChildSchema()->fill();
                                }),
                            Group::make()->key('bulkProductValue')->schema(fn (Get $get) => [self::valueField($get('field'))]),
                        ]),
                ]),
                Step::make('Megerősítés')->schema([
                    Text::make(fn ($livewire) => 'Biztos módosítani kívánsz '.count(self::selectedIds(null, $livewire)).' db terméket a megadott adatokkal?'),
                    Text::make(fn (Get $get) => self::summary($get('changes') ?? [])),
                    Checkbox::make('confirmed')->label('Igen, jóváhagyom a kijelölt termékek felülírását.')->accepted()->required(),
                ]),
            ])
            ->action(function (array $data, $records, BulkAction $action, $livewire): void {
                try {
                    $result = app(ProductBulkUpdateService::class)->update(self::selectedIds($records, $livewire), $data['changes'], $data['selection_token']);
                } catch (DomainException|ValidationException $exception) {
                    Notification::make()->danger()->title('A termékek nem módosíthatók')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Tömeges módosítás kész')
                    ->body('Kijelölt: '.$result['selected'].', módosított: '.$result['updated'].', változatlan: '.$result['unchanged']
                        .', felülvizsgálatra jelölt ársor: '.$result['marked_for_review'].'.')->send();
                $livewire->deselectAllTableRecords();
            });
    }

    private static function selectedIds($records, $livewire): array
    {
        return $livewire->isTrackingDeselectedTableRecords
            ? ($records ?? $livewire->getSelectedTableRecords())->modelKeys() : $livewire->selectedTableRecords;
    }

    private static function valueField(mixed $field): Field
    {
        $definition = is_string($field) ? (ProductBulkUpdateService::fields()[$field] ?? null) : null;
        if ($definition === null) {
            return TextInput::make('value')->label('Új érték')->disabled()->placeholder('Előbb válassz mezőt.');
        }
        $input = match ($definition['type']) {
            'relation' => Select::make('value')->options(fn () => ProductBulkUpdateService::options($field))->searchable()->preload(),
            'boolean' => Select::make('value')->options([1 => 'Igen', 0 => 'Nem']),
            'date' => DatePicker::make('value')->native(false)->displayFormat('Y.m.d.'),
            'textarea' => Textarea::make('value')->rows(3)->maxLength(16000),
            'integer' => TextInput::make('value')->integer(),
            default => TextInput::make('value')->maxLength(255),
        };

        return $input->label('Új érték')->required($definition['required'] ?? false)
            ->rules(ProductBulkUpdateService::valueRules($field));
    }

    private static function summary(array $changes): string
    {
        $summary = [];
        foreach ($changes as $change) {
            $field = $change['field'] ?? '';
            $definition = is_string($field) ? (ProductBulkUpdateService::fields()[$field] ?? null) : null;
            if (! $definition) {
                continue;
            }
            $value = $change['value'] ?? null;
            $value = match (true) {
                $value === null || $value === '' => '(üresre állítás)',
                $definition['type'] === 'relation' => ProductBulkUpdateService::options($field)[$value] ?? (string) $value,
                $definition['type'] === 'boolean' => $value ? 'Igen' : 'Nem',
                default => (string) $value,
            };
            $summary[] = $definition['label'].': '.$value;
        }

        return implode(' | ', $summary);
    }
}
