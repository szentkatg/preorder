<?php

namespace App\Filament\Resources\PartnerUsers\Tables;

use App\Exports\GenericTableExport;
use App\Models\PartnerUser;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

class PartnerUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('partner.name')
                    ->label('Partner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('role')
                    ->label('Szerepkör')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PartnerUser::ROLE_PARTNER_ADMIN => 'Partner admin',
                        PartnerUser::ROLE_ADDRESS_USER => 'Cím felhasználó',
                        PartnerUser::ROLE_SALES_REP => 'Területi képviselő',
                        default => $state ?? '-',
                    })
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Név')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),

                TextColumn::make('addresses_count')
                    ->label('Címek')
                    ->counts('addresses')
                    ->sortable(),

                TextColumn::make('partners_count')
                    ->label('Egyedi partnerek')
                    ->counts('partners')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Létrehozva')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Módosítva')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => Excel::download(
                        new GenericTableExport(PartnerUser::class),
                        'partner_users.xlsx'
                    )),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
