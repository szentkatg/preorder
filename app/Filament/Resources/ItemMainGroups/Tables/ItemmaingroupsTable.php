<?php

namespace App\Filament\Resources\Itemmaingroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class ItemmaingroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
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
