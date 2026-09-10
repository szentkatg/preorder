<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('password')
                    ->label('Jelszó')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),

                Select::make('roles')
                    ->label('Szerepkörök')
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('guard_name', 'web')
                            ->orderBy('name'),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): ?string => $record?->is(auth()->user())
                        ? 'A saját szerepköreid ezen a felületen nem módosíthatók.'
                        : null),
            ])
            ->columns(2);
    }
}
