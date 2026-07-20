<?php

namespace App\Filament\Resources\RoundingRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RoundingRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),

                TextInput::make('name')
                    ->label('Megnevezés')
                    ->required()
                    ->maxLength(255),

                Toggle::make('include_assortments')
                    ->label('Gyűjtőcikkek figyelembevétele')
                    ->default(false),

                Select::make('rounding_multiple')
                    ->label('Kerekítés többszöröse')
                    ->required()
                    ->options([
                        5 => '5',
                        10 => '10',
                    ]),

                Select::make('rounding_mode')
                    ->label('Kerekítés módja')
                    ->required()
                    ->default('threshold')
                    ->options([
                        'threshold' => 'Küszöb szerint',
                        'floor' => 'Mindig lefelé',
                        'ceil' => 'Mindig felfelé',
                    ])
                    ->live(),

                TextInput::make('round_up_from_remainder')
                    ->label('Felfelé ettől a maradéktól')
                    ->numeric()
                    ->visible(fn (Get $get): bool => $get('rounding_mode') === 'threshold'),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}