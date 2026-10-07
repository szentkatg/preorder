<?php

namespace App\Filament\Resources\PricingRoundingRules;

use App\Filament\Resources\PricingRoundingRules\Pages\CreatePricingRoundingRule;
use App\Filament\Resources\PricingRoundingRules\Pages\EditPricingRoundingRule;
use App\Filament\Resources\PricingRoundingRules\Pages\ListPricingRoundingRules;
use App\Filament\Resources\PricingRoundingRules\Pages\ViewPricingRoundingRule;
use App\Filament\Resources\PricingRoundingRules\Schemas\PricingRoundingRuleForm;
use App\Filament\Resources\PricingRoundingRules\Tables\PricingRoundingRulesTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingRoundingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingRoundingRuleResource extends Resource
{
    protected static ?string $model = PricingRoundingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $modelLabel = 'árkerekítési szabály';

    protected static ?string $pluralModelLabel = 'árkerekítési szabályok';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return PricingRoundingRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingRoundingRulesTable::configure($table),
            PricingRoundingRule::class,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingRoundingRules::route('/'),
            'create' => CreatePricingRoundingRule::route('/create'),
            'view' => ViewPricingRoundingRule::route('/{record}'),
            'edit' => EditPricingRoundingRule::route('/{record}/edit'),
        ];
    }
}
