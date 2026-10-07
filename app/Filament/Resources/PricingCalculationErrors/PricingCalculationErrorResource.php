<?php

namespace App\Filament\Resources\PricingCalculationErrors;

use App\Filament\Resources\PricingCalculationErrors\Pages\ListPricingCalculationErrors;
use App\Filament\Resources\PricingCalculationErrors\Pages\ViewPricingCalculationError;
use App\Filament\Resources\PricingCalculationErrors\Tables\PricingCalculationErrorsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationError;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingCalculationErrorResource extends Resource
{
    protected static ?string $model = PricingCalculationError::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $modelLabel = 'árkalkulációs hiba';

    protected static ?string $pluralModelLabel = 'árkalkulációs hibák';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('run_id')
                    ->label('Futás azonosító')
                    ->disabled(),
                Placeholder::make('pricing_project')
                    ->label('Árprojekt')
                    ->content(fn (?PricingCalculationError $record): string => $record?->pricingProject?->name ?? '-'),
                Placeholder::make('product_model_code')
                    ->label('Modell')
                    ->content(fn (?PricingCalculationError $record): string => $record?->product?->model_code ?? '-'),
                Placeholder::make('color_code')
                    ->label('Szín')
                    ->content(fn (?PricingCalculationError $record): string => $record?->color?->code ?? 'Általános'),
                Placeholder::make('supplier_name')
                    ->label('Beszállító')
                    ->content(fn (?PricingCalculationError $record): string => $record?->supplier?->short_name ?? $record?->supplier?->name ?? '-'),
                TextInput::make('severity')
                    ->label('Szint')
                    ->disabled(),
                Textarea::make('message')
                    ->label('Hiba oka')
                    ->rows(3)
                    ->disabled()
                    ->columnSpanFull(),
                Textarea::make('context')
                    ->label('Részletek')
                    ->formatStateUsing(
                        fn ($state): string => json_encode(
                            $state,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        ) ?: ''
                    )
                    ->rows(12)
                    ->disabled()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingCalculationErrorsTable::configure($table),
            PricingCalculationError::class,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingCalculationErrors::route('/'),
            'view' => ViewPricingCalculationError::route('/{record}'),
        ];
    }
}
