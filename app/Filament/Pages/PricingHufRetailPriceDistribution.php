<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingProjectContext;
use App\Models\HufRetailPriceDistributionRow;
use App\Services\Pricing\HufRetailPriceDistribution;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PricingHufRetailPriceDistribution extends Page implements HasTable
{
    use HasPricingProjectContext;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'HUF kisker áreloszlás';

    protected static ?string $title = 'HUF kisker áreloszlás főcsoportonként';

    protected static ?int $navigationSort = 37;

    protected string $view = 'filament.pages.pricing-huf-retail-price-distribution';

    public static function canAccess(): bool
    {
        return PricingHufRetailCalculations::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->initialisePricingProjectContext();
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure($table
            ->query(fn (): Builder => app(HufRetailPriceDistribution::class)->query($this->pricingProjectId))
            ->modifyQueryUsing(fn (Builder $query) => $this->scopePricingProjectContext($query))
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('itemMainGroup.code')->label('Főcsoport kód'),
                TextColumn::make('itemMainGroup.name')->label('Főcsoport név'),
                TextColumn::make('final_price')->label('Végleges kisker HUF')->numeric()
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 4, ',', ' '), '0'), ',')),
                TextColumn::make('model_count')->label('Modellek száma')->numeric(decimalPlaces: 0),
                TextColumn::make('color_count')->label('Színállások száma')->numeric(decimalPlaces: 0),
            ])
            ->filters([
                SelectFilter::make('price_list_id')->label('Árlista')->relationship('priceList', 'code',
                    fn (Builder $query) => $query->where('type', 'retail')->whereHas('currency', fn ($currency) => $currency->where('code', 'HUF')))
                    ->multiple()->searchable()->preload(),
                SelectFilter::make('item_main_group_id')->label('Főcsoport')->relationship('itemMainGroup', 'name')
                    ->multiple()->searchable()->preload(),
            ])
            ->defaultSort('final_price')->recordActions([])->recordAction(null)->recordUrl(null)
            ->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all']),
            HufRetailPriceDistributionRow::class, withViewAction: false);
    }
}
