<?php

namespace App\Filament\Resources\PartnerUsers;

use App\Filament\Resources\PartnerUsers\Pages\CreatePartnerUser;
use App\Filament\Resources\PartnerUsers\Pages\EditPartnerUser;
use App\Filament\Resources\PartnerUsers\Pages\ListPartnerUsers;
use App\Filament\Resources\PartnerUsers\Schemas\PartnerUserForm;
use App\Filament\Resources\PartnerUsers\Tables\PartnerUsersTable;
use App\Models\PartnerUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PartnerUserResource extends Resource
{
    protected static ?string $model = PartnerUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PartnerUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnerUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerUsers::route('/'),
            'create' => CreatePartnerUser::route('/create'),
            'edit' => EditPartnerUser::route('/{record}/edit'),
        ];
    }
}
