<?php

namespace App\Filament\Support;

use App\Exports\AdminTableExport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartnerUser;
use App\Models\Translation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AdminResourceTable
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function configure(
        Table $table,
        string $modelClass,
        bool $withViewAction = true,
    ): Table
    {
        foreach ($table->getColumns() as $column) {
            self::configureColumn($column, $modelClass);
        }

        if ($withViewAction && (! $table->hasAction('view'))) {
            $table->recordActions([
                ViewAction::make(),
                ...$table->getRecordActions(),
            ]);
        }

        if (! $table->hasAction('exportExcel')) {
            $columns = array_values($table->getColumns());
            $tableName = (new $modelClass)->getTable();

            $table->pushHeaderActions([
                Action::make('exportExcel')
                    ->label('Exportálás Excelbe')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (HasTable $livewire) use ($columns, $tableName) {
                        $query = $livewire->getTableQueryForExport();

                        foreach ($columns as $column) {
                            $column->applyRelationshipAggregates($query);
                            $column->applyEagerLoading($query);
                        }

                        return Excel::download(
                            new AdminTableExport(
                                $query,
                                $columns,
                            ),
                            $tableName.'-'.now()->format('Ymd-His').'.xlsx',
                        );
                    }),
            ]);
        }

        return $table;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function configureColumn(Column $column, string $modelClass): void
    {
        $column
            ->sortable()
            ->searchable(isIndividual: true);

        if ($modelClass === Order::class) {
            self::configureOrderTotalColumn($column);
        }

        if ($modelClass === OrderItem::class) {
            self::configureOrderItemCalculatedColumn($column);
        }

        if ($modelClass === PartnerUser::class) {
            self::configureCountColumn($column);
        }

        if (($modelClass === User::class) && ($column->getName() === 'roles.name')) {
            $column->sortable(
                query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    self::userRoleNameSubquery(),
                    $direction,
                ),
            );
        }

        if (($modelClass === Translation::class) && ($column->getName() === 'original_value')) {
            self::configureTranslationOriginalValueColumn($column);
        }
    }

    private static function configureOrderTotalColumn(Column $column): void
    {
        $subquery = match ($column->getName()) {
            'total_ordered_units' => fn (): QueryBuilder => self::orderTotalSubquery('order_items.quantity'),
            'total_effective_quantity' => fn (): QueryBuilder => self::orderEffectiveQuantitySubquery(),
            'total_value' => fn (): QueryBuilder => self::orderTotalSubquery('order_items.line_total'),
            default => null,
        };

        if ($subquery === null) {
            return;
        }

        $column
            ->sortable(
                query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    $subquery(),
                    $direction,
                ),
            )
            ->searchable(
                query: function (Builder $query, string $search) use ($subquery): Builder {
                    if (! is_numeric($search)) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->where($subquery(), '=', $search);
                },
                isIndividual: true,
            );
    }

    private static function configureCountColumn(Column $column): void
    {
        $relationship = match ($column->getName()) {
            'addresses_count' => 'addresses',
            'partners_count' => 'partners',
            default => null,
        };

        if ($relationship === null) {
            return;
        }

        $column->searchable(
            query: function (Builder $query, string $search) use ($relationship): Builder {
                if (! ctype_digit($search)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->has($relationship, '=', (int) $search);
            },
            isIndividual: true,
        );
    }

    private static function configureOrderItemCalculatedColumn(Column $column): void
    {
        $subquery = match ($column->getName()) {
            'assortment_content' => fn (): QueryBuilder => self::assortmentContentSubquery(),
            'effective_quantity' => fn (): QueryBuilder => self::orderItemEffectiveQuantitySubquery(),
            default => null,
        };

        if ($subquery === null) {
            return;
        }

        $column
            ->sortable(
                query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    $subquery(),
                    $direction,
                ),
            )
            ->searchable(
                query: function (Builder $query, string $search) use ($subquery): Builder {
                    if (! is_numeric($search)) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->where($subquery(), '=', $search);
                },
                isIndividual: true,
            );
    }

    private static function configureTranslationOriginalValueColumn(Column $column): void
    {
        $expression = <<<'SQL'
CASE translations.entity
    WHEN 'brand' THEN (SELECT brands.name FROM brands WHERE brands.code = translations.entity_code LIMIT 1)
    WHEN 'currency' THEN (SELECT currencies.name FROM currencies WHERE currencies.code = translations.entity_code LIMIT 1)
    WHEN 'item_main_group' THEN (SELECT item_main_groups.name FROM item_main_groups WHERE item_main_groups.code = translations.entity_code LIMIT 1)
    WHEN 'language' THEN (SELECT languages.name FROM languages WHERE languages.code = translations.entity_code LIMIT 1)
    WHEN 'order_sheet_type' THEN (SELECT order_sheet_types.name FROM order_sheet_types WHERE order_sheet_types.code = translations.entity_code LIMIT 1)
    WHEN 'season' THEN (SELECT seasons.name FROM seasons WHERE seasons.code = translations.entity_code LIMIT 1)
END
SQL;

        $column
            ->sortable(
                query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                    "{$expression} ".($direction === 'desc' ? 'desc' : 'asc'),
                ),
            )
            ->searchable(
                query: fn (Builder $query, string $search): Builder => $query->whereRaw(
                    "{$expression} LIKE ?",
                    ["%{$search}%"],
                ),
                isIndividual: true,
            );
    }

    private static function orderTotalSubquery(string $expression): QueryBuilder
    {
        return DB::table('order_items')
            ->selectRaw("COALESCE(SUM({$expression}), 0)")
            ->whereColumn('order_items.order_id', 'orders.id');
    }

    private static function orderEffectiveQuantitySubquery(): QueryBuilder
    {
        return DB::table('order_items')
            ->selectRaw(<<<'SQL'
COALESCE(SUM(
    order_items.quantity * COALESCE(
        NULLIF((
            SELECT SUM(item_assortments.quantity)
            FROM item_assortments
            WHERE item_assortments.assortment_sku_id = order_items.sku_id
        ), 0),
        1
    )
), 0)
SQL)
            ->whereColumn('order_items.order_id', 'orders.id');
    }

    private static function assortmentContentSubquery(): QueryBuilder
    {
        return DB::table('item_assortments')
            ->selectRaw('COALESCE(SUM(item_assortments.quantity), 0)')
            ->whereColumn('item_assortments.assortment_sku_id', 'order_items.sku_id');
    }

    private static function userRoleNameSubquery(): QueryBuilder
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('MIN(roles.name)')
            ->whereColumn('model_has_roles.model_id', 'users.id')
            ->where('model_has_roles.model_type', User::class);
    }

    private static function orderItemEffectiveQuantitySubquery(): QueryBuilder
    {
        return DB::table('item_assortments')
            ->selectRaw(<<<'SQL'
order_items.quantity * COALESCE(
    NULLIF(SUM(item_assortments.quantity), 0),
    1
)
SQL)
            ->whereColumn('item_assortments.assortment_sku_id', 'order_items.sku_id');
    }
}
