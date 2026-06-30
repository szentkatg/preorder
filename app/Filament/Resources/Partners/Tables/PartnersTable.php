<?php

namespace App\Filament\Resources\Partners\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericTableExport;
use App\Models\Partner;


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

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Telefon'),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
                    ->headerActions([
                        Action::make('export')
                            ->label('Export')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->action(fn () => Excel::download(
                                new GenericTableExport(Partner::class),
                                'partners.xlsx'
                            )),
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