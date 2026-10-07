<?php

namespace App\Filament\Resources\PricingProjects\Schemas;

use App\Models\PricingProject;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PricingProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                Select::make('status')
                    ->label('Státusz')
                    ->options([
                        PricingProject::STATUS_DRAFT => 'Tervezet',
                        PricingProject::STATUS_ACTIVE => 'Aktív',
                        PricingProject::STATUS_CLOSED => 'Lezárt',
                    ])
                    ->default(PricingProject::STATUS_DRAFT)
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ])
            ->columns(2);
    }
}
