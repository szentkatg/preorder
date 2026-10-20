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
                    ->disabled(fn (?PricingProject $record) => $record?->exists)
                    ->label('Szezon')
                    ->helperText('Létrehozás után a szezon még szabad termékei közül választhatod ki az árprojekt tételeit.')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('name')
                    ->disabled(fn (?PricingProject $record) => $record?->status === 'closed')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                Select::make('status')
                    ->label('Státusz')
                    ->disabled(fn (?PricingProject $record) => $record?->status === 'closed')
                    ->options(fn (?PricingProject $record) => $record?->status === 'closed' ? ['closed' => 'Lezárt'] : [
                        PricingProject::STATUS_DRAFT => 'Tervezet',
                        PricingProject::STATUS_ACTIVE => 'Aktív',
                    ])
                    ->default(PricingProject::STATUS_DRAFT)
                    ->required(),

                Toggle::make('active')
                    ->disabled(fn (?PricingProject $record) => $record?->status === 'closed')
                    ->label('Aktív')
                    ->default(true),
            ])
            ->columns(2);
    }
}
