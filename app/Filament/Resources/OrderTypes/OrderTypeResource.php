<?php

namespace App\Filament\Resources\OrderTypes;

use App\Filament\Resources\OrderTypes\Pages\CreateOrderType;
use App\Filament\Resources\OrderTypes\Pages\EditOrderType;
use App\Filament\Resources\OrderTypes\Pages\ListOrderTypes;
use App\Filament\Resources\OrderTypes\Pages\ViewOrderType;
use App\Filament\Resources\OrderTypes\Schemas\OrderTypeForm;
use App\Filament\Resources\OrderTypes\Tables\OrderTypesTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\OrderType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderTypeResource extends Resource
{
    protected static ?string $model = OrderType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Rendeléstípusok';

    protected static ?string $modelLabel = 'rendeléstípus';

    protected static ?string $pluralModelLabel = 'rendeléstípusok';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OrderTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            OrderTypesTable::configure($table),
            OrderType::class,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderTypes::route('/'),
            'create' => CreateOrderType::route('/create'),
            'view' => ViewOrderType::route('/{record}'),
            'edit' => EditOrderType::route('/{record}/edit'),
        ];
    }
}
