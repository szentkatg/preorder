<?php

namespace App\Filament\Resources\RoundingRules;

use App\Filament\Resources\RoundingRules\Pages\CreateRoundingRule;
use App\Filament\Resources\RoundingRules\Pages\EditRoundingRule;
use App\Filament\Resources\RoundingRules\Pages\ListRoundingRules;
use App\Filament\Resources\RoundingRules\Schemas\RoundingRuleForm;
use App\Filament\Resources\RoundingRules\Tables\RoundingRulesTable;
use App\Models\RoundingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RoundingRuleResource extends Resource
{
    protected static ?string $model = RoundingRule::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup =
        'Törzsadatok';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel =
        'Gyártási kerekítések';

    protected static ?string $modelLabel =
        'gyártási kerekítés';

    protected static ?string $pluralModelLabel =
        'gyártási kerekítések';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoundingRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoundingRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoundingRules::route('/'),
            'create' => CreateRoundingRule::route('/create'),
            'edit' => EditRoundingRule::route('/{record}/edit'),
        ];
    }
}