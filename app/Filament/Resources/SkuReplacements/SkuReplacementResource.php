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
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
        return $schema->components([
            Select::make('season_id')->label('Szezon')->required()->live()->searchable()
                ->options(fn () => Season::orderByDesc('id')->get()->mapWithKeys(fn ($season) => [$season->id => $season->code.' – '.$season->name]))
                ->afterStateUpdated(function (Set $set): void {
                    $set('old_sku_code', null);
                    $set('new_sku_code', null);
                }),
            static::skuSelect('old_sku_code', true)->label('Régi cikkszám (SKU)')
                ->helperText('Keresés cikkszám vagy SKU-név részletére. Csak az adott szezonban még cseréhez nem rendelt régi cikkek választhatók.'),
            static::skuSelect('new_sku_code', false)->label('Új cikkszám vagy TÖRÖLT')
                ->helperText('TÖRÖLT esetén a mennyiség kimarad az ERP-exportból. Az eredeti rendelés nem módosul.'),
        ]);
    }

    protected static function skuSelect(string $field, bool $old): Select
    {
        return Select::make($field)->required()->searchable()->searchDebounce(300)
            ->disabled(fn (Get $get): bool => blank($get('season_id')))
            ->searchPrompt('Kezdd el gépelni a cikkszámot vagy az SKU nevét…')
            ->noSearchResultsMessage('Nincs megfelelő cikk a kiválasztott szezonban.')
            ->options(fn (Get $get, ?SkuReplacement $record): array => static::skuOptions($get('season_id'), '', $old, $record))
            ->getSearchResultsUsing(fn (string $search, Get $get, ?SkuReplacement $record): array => static::skuOptions($get('season_id'), $search, $old, $record))
            ->getOptionLabelUsing(function ($value, Get $get, ?SkuReplacement $record) use ($old): ?string {
                if (blank($get('season_id')) || blank($value)) {
                    return null;
                }
                if (! $old && mb_strtoupper(trim((string) $value), 'UTF-8') === SkuReplacement::DELETED) {
                    return SkuReplacement::DELETED;
                }
                $sku = static::skuQuery((int) $get('season_id'), $old, $record)->where('sku_code', $value)->first(['sku_code', 'sku_name']);

                return $sku ? static::skuLabel($sku) : null;
            });
    }

    protected static function skuOptions(?int $seasonId, string $search, bool $old, ?SkuReplacement $record): array
    {
        if (! $seasonId) {
            return [];
        }
        $query = static::skuQuery($seasonId, $old, $record);
        $search = mb_strtolower(trim($search), 'UTF-8');
        if ($search !== '') {
            // Search literal substrings: user-entered % and _ are not wildcards.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $query) => $query
                ->whereRaw("LOWER(sku_code) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(sku_name) LIKE ? ESCAPE '!'", [$like]));
        }
        $options = $query->orderBy('sku_code')->limit(50)->get(['sku_code', 'sku_name'])
            ->mapWithKeys(fn (Sku $sku): array => [$sku->sku_code => static::skuLabel($sku)])->all();
        if (! $old && ($search === '' || str_contains(mb_strtolower(SkuReplacement::DELETED, 'UTF-8'), $search))) {
            $options = [SkuReplacement::DELETED => SkuReplacement::DELETED] + $options;
        }

        return $options;
    }

    protected static function skuQuery(int $seasonId, bool $old, ?SkuReplacement $record): Builder
    {
        return Sku::whereHas('product', fn (Builder $query) => $query->where('season_id', $seasonId))
            ->when($old, fn (Builder $query) => $query->whereNotIn('sku_code', SkuReplacement::query()
                ->select('old_sku_code')->where('season_id', $seasonId)
                ->when($record?->exists, fn (Builder $query) => $query->whereKeyNot($record->id))));
    }

    protected static function skuLabel(Sku $sku): string
    {
        return filled($sku->sku_name) ? $sku->sku_code.' – '.$sku->sku_name : $sku->sku_code;
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure($table->columns([
            TextColumn::make('season.code')->label('Szezon'),
            TextColumn::make('old_sku_code')->label('Régi cikkszám'),
            TextColumn::make('oldSku.sku_name')->label('Régi SKU neve')->placeholder('—'),
            TextColumn::make('new_sku_code')->label('Új cikkszám / TÖRÖLT'),
            TextColumn::make('newSku.sku_name')->label('Új SKU neve')->placeholder('—'),
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
            'import' => Pages\ImportSkuReplacements::route('/import'),
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
