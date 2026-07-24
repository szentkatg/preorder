<?php

namespace App\Filament\Resources\Translations\Schemas;

use App\Models\Language;
use App\Models\Translation;
use App\Services\Translation\TranslationRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class TranslationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fordítás adatai')
                    ->description(
                        'Egy entitás adott mezőjének nyelvi fordítása.'
                    )
                    ->schema([
                        Select::make('entity')
                            ->label('Entitás')
                            ->options(
                                TranslationRegistry::entityOptions()
                            )
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(
                                function (Set $set): void {
                                    $set('field', null);
                                }
                            ),

                        TextInput::make('entity_code')
                            ->label('Entitás kódja')
                            ->required()
                            ->maxLength(100)
                            ->helperText(
                                'Például: EUR, HT, S27 vagy T35050S27.'
                            )
                            ->dehydrateStateUsing(
                                fn (?string $state): ?string => filled($state)
                                    ? trim($state)
                                    : null
                            ),

                        Select::make('field')
                            ->label('Fordítandó mező')
                            ->options(
                                fn (Get $get): array =>
                                    TranslationRegistry::fieldOptions(
                                        $get('entity')
                                    )
                            )
                            ->required()
                            ->native(false)
                            ->disabled(
                                fn (Get $get): bool =>
                                    blank($get('entity'))
                            ),

                        Select::make('language_id')
                            ->label('Nyelv')
                            ->options(
                                fn (): array => Language::query()
                                    ->where('active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(
                                        fn (Language $language): array => [
                                            $language->getKey() =>
                                                self::languageLabel($language),
                                        ]
                                    )
                                    ->all()
                            )
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->rules(
                                fn (
                                    Get $get,
                                    ?Translation $record
                                ): array => [
                                    Rule::unique(
                                        table: 'translations',
                                        column: 'language_id'
                                    )
                                        ->where(
                                            fn ($query) =>
                                                $query
                                                    ->where(
                                                        'entity',
                                                        $get('entity')
                                                    )
                                                    ->where(
                                                        'entity_code',
                                                        $get('entity_code')
                                                    )
                                                    ->where(
                                                        'field',
                                                        $get('field')
                                                    )
                                        )
                                        ->ignore(
                                            $record?->getKey(),
                                            'translation_id'
                                        ),
                                ]
                            )
                            ->validationMessages([
                                'unique' =>
                                    'Ehhez az entitáshoz, mezőhöz és nyelvhez már létezik fordítás.',
                            ]),

                        Textarea::make('value')
                            ->label('Fordítás')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull()
                            ->dehydrateStateUsing(
                                fn (?string $state): ?string => filled($state)
                                    ? trim($state)
                                    : null
                            ),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function languageLabel(Language $language): string
    {
        $name = $language->translate('name');

        if (blank($name)) {
            return strtoupper($language->code);
        }

        return sprintf(
            '%s – %s',
            strtoupper($language->code),
            $name
        );
    }
}
