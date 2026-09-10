<?php

namespace App\Filament\Resources\PartnerAddresses;

use App\Filament\Resources\PartnerAddresses\Pages\CreatePartnerAddress;
use App\Filament\Resources\PartnerAddresses\Pages\EditPartnerAddress;
use App\Filament\Resources\PartnerAddresses\Pages\ListPartnerAddresses;
use App\Filament\Resources\PartnerAddresses\Pages\ViewPartnerAddress;
use App\Filament\Resources\PartnerAddresses\Schemas\PartnerAddressForm;
use App\Filament\Resources\PartnerAddresses\Tables\PartnerAddressesTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PartnerAddress;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PartnerAddressResource extends Resource
{
    protected static ?string $model = PartnerAddress::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PartnerAddressForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PartnerAddressesTable::configure($table),
            PartnerAddress::class,
        );
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
            'index' => ListPartnerAddresses::route('/'),
            'create' => CreatePartnerAddress::route('/create'),
            'view' => ViewPartnerAddress::route('/{record}'),
            'edit' => EditPartnerAddress::route('/{record}/edit'),
        ];
    }
}
