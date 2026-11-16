<?php

namespace App\Filament\Resources\SkuReplacements;

use App\Filament\Support\AdminResourceTable;
use App\Models\Season;
use App\Models\Sku;
use App\Models\SkuReplacement;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class SkuReplacementResource extends Resource
{
    protected static ?string $model = SkuReplacement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Rendelések';

    protected static ?string $navigationLabel = 'Cikkcserék';

    protected static ?string $modelLabel = 'cikkcsere';

    protected static ?string $pluralModelLabel = 'cikkcserék';

    protected static ?string $recordTitleAttribute = 'old_sku_code';

    public static function form(Schema $schema): Schema
    {
        $suggestions = fn (Get $get) => Sku::whereHas('product', fn ($query) => $query->where('season_id', $get('season_id')))
            ->orderBy('sku_code')->limit(100)->pluck('sku_code')->all();

        return $schema->components([
            Select::make('season_id')->label('Szezon')->required()->live()->searchable()
                ->options(fn () => Season::orderByDesc('id')->get()->mapWithKeys(fn ($season) => [$season->id => $season->code.' – '.$season->name])),
            TextInput::make('old_sku_code')->label('Régi cikkszám (SKU)')->required()->maxLength(255)->datalist($suggestions)
                ->helperText('Létező cikkszám a kiválasztott szezonból.'),
            TextInput::make('new_sku_code')->label('Új cikkszám vagy TÖRÖLT')->required()->maxLength(255)
                ->datalist(fn (Get $get) => [SkuReplacement::DELETED, ...$suggestions($get)])
                ->helperText('TÖRÖLT esetén a mennyiség kimarad az ERP-exportból. Az eredeti rendelés nem módosul.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure($table->columns([
            TextColumn::make('season.code')->label('Szezon'),
            TextColumn::make('old_sku_code')->label('Régi cikkszám'),
            TextColumn::make('new_sku_code')->label('Új cikkszám / TÖRÖLT'),
        ])->filters([
            SelectFilter::make('season_id')->label('Szezon')->relationship('season', 'code')->multiple()->searchable()->preload(),
        ])->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])->defaultSort('old_sku_code'), SkuReplacement::class);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkuReplacements::route('/'),
            'create' => Pages\CreateSkuReplacement::route('/create'),
            'view' => Pages\ViewSkuReplacement::route('/{record}'),
            'edit' => Pages\EditSkuReplacement::route('/{record}/edit'),
        ];
    }

    public static function saveWithFormErrors(Closure $save): Model
    {
        try {
            return $save();
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())
                ->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
    }
}
