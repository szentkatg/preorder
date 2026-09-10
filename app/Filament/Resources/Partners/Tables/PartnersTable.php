<?php

namespace App\Filament\Resources\Partners\Tables;

use App\Models\Partner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Név')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('erp_partner_code')
                    ->label('ERP Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('salesRepresentative.name')
                    ->label('Területi képviselő')
                    ->description(fn (Partner $record): ?string => $record->sales_rep_erp_partner_code)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Telefon'),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
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
