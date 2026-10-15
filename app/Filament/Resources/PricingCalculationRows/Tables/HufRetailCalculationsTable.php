<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Filament\Support\PricingGrid;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\RetailPriceReviewService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class HufRetailCalculationsTable
{
    public static function configure(Table $table, string $currency = 'HUF', string $reviewService = RetailPriceReviewService::class): Table
    {
        return PricingGrid::rows($table)->columns([
            TextColumn::make('pricingProject.name')->label('Árprojekt'),
            TextColumn::make('priceList.code')->label('Árlista'),
            TextColumn::make('product.catalog_group_name_hu')->label('Katalógus csoport'),
            TextColumn::make('product.itemMainGroup.name_hu')->label('Főcsoport')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('product.model_code')->label('Modell kód'),
            TextColumn::make('product.name_hu')->label('Modell név'),
            TextColumn::make('color.name_hu')->label('Szín'),
            TextColumn::make('previous_season_price')->label('Előző szezon kisker '.$currency)
                ->numeric()->placeholder('-')
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'previous_season_price'))
                ->tooltip(fn (PricingCalculationRow $record): string => match (true) {
                    (bool) $record->product?->previous_season_match_override && ! $record->product?->previous_season_product_id => 'Kézzel megjelölve: nincs előző szezonbeli pár.',
                    ! $record->product?->previous_season_match_override && $record->previous_season_match_count > 1 => 'Több névegyezés az előző szezonban ('.$record->previous_season_match_count.'). Válassz kézi párosítást.',
                    ! $record->matched_previous_product_id => 'Nincs egyértelmű termékpár az előző azonos típusú szezonban.',
                    $record->previous_season_price === null => 'A termékpár megvan, de az azonos árlistában nincs előző szezonbeli árlistatétel.',
                    default => ($record->product?->previous_season_match_override ? 'Kézi' : 'Automatikus').' párosítás. Az azonos árlista előző szezonbeli aktuális ára.',
                }),
            TextColumn::make('supplier.erp_partner_code')->label('Beszállító kód')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('supplier.name')->label('Beszállító neve')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('purchase_price')->label('Eredeti besz. ár')
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'purchase_price'))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('purchaseCurrency.code')->label('Besz. pénznem')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('source_retail_price')->label('Forrás HUF kisker')
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'source_retail_price'))->visible($currency !== 'HUF'),
            TextColumn::make('retail_exchange_rate')->label('Kisker árfolyam (HUF/'.$currency.')')
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'retail_exchange_rate', 6))->visible($currency !== 'HUF'),
            TextColumn::make('base_price')->label($currency === 'HUF' ? 'Bekerülési HUF' : 'Átváltott HUF kisker ('.$currency.')')->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'base_price')),
            TextColumn::make('multiplier')->label($currency === 'HUF' ? 'Kisker szorzó' : 'Országszorzó (kerekítés után)')
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->effective_multiplier)
                ->extraAttributes(['data-pricing-derived-multiplier' => true])
                ->extraHeaderAttributes(fn ($livewire) => self::multiplierAverageAttributes($livewire->averageRetailMultiplier))
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'multiplier', 6, true, ''))
                ->icon(fn (PricingCalculationRow $record) => self::differenceIcon($record))
                ->iconColor(fn (PricingCalculationRow $record) => self::differenceColor($record))
                ->color(fn (PricingCalculationRow $record) => self::differenceColor($record))
                ->tooltip(fn (PricingCalculationRow $record) => 'Kalkulációs szorzó: '.self::formatMultiplier($record->multiplier)
                    .'. Kerekítés utáni szorzó: '.self::formatMultiplier(
                        (float) $record->base_price > 0 ? (float) $record->calculated_price / (float) $record->base_price : null,
                    ).'. A kijelzett szorzó a végleges ár / '.($currency === 'HUF' ? 'bekerülési érték' : 'devizára átváltott HUF kisker ár').'. A nyíl a kalkulált, kerekített árhoz viszonyított eltérést jelzi.'),
            TextColumn::make('cost_based_multiplier')->label('Bekerülési szorzó')->visible($currency !== 'HUF')
                ->numeric()->placeholder('-')
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'cost_based_multiplier', 6, true, ''))
                ->extraHeaderAttributes(fn ($livewire) => self::multiplierAverageAttributes($livewire->averageCostBasedMultiplier,
                    'A szűrt sorok bekerülési szorzóinak számtani átlaga'))
                ->tooltip('Végleges devizás kisker ár × a kalkuláció mentett árfolyama ÷ a hozzá tartozó HUF kisker sor bekerülési alapja. Hiányzó vagy nem pozitív bekerülési értéknél/árfolyamnál nincs szorzó.'),
            TextColumn::make('calculation_multiplier')->label('Kalkulációs szorzó')
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->multiplier === null ? null : (float) $record->multiplier)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'calculation_multiplier', 6, true, ''))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('unrounded_price')->label('Kerekítés előtti '.$currency)->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'unrounded_price')),
            TextColumn::make('pricingRoundingRule.name')->label('Kerekítési szabály')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('calculated_price')->label('Kalkulált kisker '.$currency)->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'calculated_price')),
            PricingGrid::manualColumn('manual_price', 'Manuális '.$currency),
            TextColumn::make('final_price')->label('Végleges kisker '.$currency)->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'final_price'))
                ->icon(fn (PricingCalculationRow $record) => self::differenceIcon($record))
                ->iconColor(fn (PricingCalculationRow $record) => self::differenceColor($record))
                ->color(fn (PricingCalculationRow $record) => self::differenceColor($record)),
            TextColumn::make('target_purchase_price')->label('Cél besz. ár')
                ->visible($currency === 'HUF')
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->target_purchase_price === null ? null : (float) $record->target_purchase_price)
                ->placeholder('-')
                ->formatStateUsing(fn ($state, PricingCalculationRow $record, $livewire) => $state === null
                    ? '-' : $livewire->formatPricingDecimal($state, 'target_purchase_price').' '.($record->purchaseCurrency?->code ?? ''))
                ->color(fn (PricingCalculationRow $record) => $record->needsPurchasePriceNegotiation() ? 'danger' : null)
                ->tooltip(fn (PricingCalculationRow $record) => 'Eredeti besz. ár: '.self::formatPrice($record->purchase_price)
                    .' '.($record->purchaseCurrency?->code ?? '').'. Célár = eredeti besz. ár × végleges kisker ár / (bekerülési érték × kalkulációs szorzó). Csak manuális/megtartott árnál számolunk csökkentést, felfelé nem módosítunk.'),
            IconColumn::make('requires_review')->label('Felülvizsgálandó')->boolean()
                ->trueColor('danger')
                ->falseColor('success'),
            TextColumn::make('status')->label('Státusz')->badge()->formatStateUsing(fn ($state) => match ($state) {
                PricingCalculationRow::STATUS_CALCULATED => 'Kalkulált ár',
                PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                PricingCalculationRow::STATUS_MODIFIED_APPROVED => 'Módosított jóváhagyott',
                PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                default => $state,
            }),
            PricingGrid::operationsColumn(),
        ])->filters([
            SelectFilter::make('pricing_project_id')->label('Árprojekt')->relationship('pricingProject', 'name')->searchable()->preload(),
            SelectFilter::make('price_list_id')->label('Árlista')->relationship('priceList', 'code')->searchable()->preload(),
            TernaryFilter::make('requires_review')->label('Felülvizsgálandó'),
        ])->toolbarActions([
            BulkAction::make('approveRetailBulk')->label('Kijelölt árak jóváhagyása')->icon('heroicon-o-check')
                ->requiresConfirmation()->modalHeading('Kijelölt árak tömeges jóváhagyása')
                ->modalDescription('A kijelölt sorok aktuális végleges árát hagyod jóvá. A manuális és megtartott árak nem változnak. Az elavult kalkulációjú vagy nem módosítható sorokat kihagyjuk, és jelezzük az eredményt.')
                ->modalSubmitActionLabel('Jóváhagyás')->modalCancelActionLabel('Mégse')
                ->schema([self::approvalStatusSelect()])
                ->visible(fn ($livewire) => self::canApproveBulk($livewire))
                ->authorize(fn ($livewire) => self::canApproveBulk($livewire))
                ->action(function (Collection $records, array $data) use ($reviewService): void {
                    $result = app($reviewService)->approveCurrentPrices($records, $data['approval_status']);
                    $body = 'Sikeres: '.$result['approved'].' sor. Kihagyott: '.$result['skipped'].' sor.';
                    foreach ($result['reasons'] as $reason => $count) {
                        $body .= ' '.$count.' sor: '.$reason;
                    }
                    $notification = Notification::make()->title('Tömeges jóváhagyás befejezve')->body($body);
                    ($result['skipped'] ? $notification->warning() : $notification->success())->send();
                })
                ->deselectRecordsAfterCompletion()
                ->after(fn ($livewire) => $livewire->resetTable()),
            $table->getLivewire()->pricingAcceptBulkAction(),
            $table->getLivewire()->pricingDetailsBulkAction(),
        ])->selectable(fn ($livewire) => self::canApproveBulk($livewire) || Gate::allows('view', new PricingCalculationRow))
            ->checkIfRecordIsSelectableUsing(fn (PricingCalculationRow $record) => self::canEdit($record) || Gate::allows('view', $record))
            ->deselectAllRecordsWhenFiltered()
            ->defaultSort('updated_at', 'desc')->reorderableColumns()->columnManagerColumns(3)
            ->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all']);
    }

    private static function multiplierAverageAttributes(?float $average, string $description = 'A szűrt sorok szorzóinak számtani átlaga'): array
    {
        $formatted = $average === null ? '-' : number_format($average, 2, ',', ' ');

        return ['data-pricing-average-multiplier' => 'Átlag: '.$formatted, 'aria-description' => $description.': '.$formatted];
    }

    public static function approvalStatusSelect(): Select
    {
        return Select::make('approval_status')->label('Átnézés / jóváhagyás szintje')->options([
            PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
            PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
            PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
            PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
        ])->default(PricingCalculationRow::STATUS_APPROVED)->required();
    }

    private static function canApproveBulk($livewire): bool
    {
        return $livewire::canAccess() && $livewire->pricingWorkspaceIsEditable()
            && Gate::allows('update', new PricingCalculationRow);
    }

    private static function formatPrice(mixed $state): string
    {
        return rtrim(rtrim(number_format((float) $state, 4, ',', ' '), '0'), ',');
    }

    private static function formatMultiplier(mixed $state): string
    {
        return $state === null ? '-' : rtrim(rtrim(number_format((float) $state, 6, ',', ''), '0'), ',');
    }

    private static function differenceIcon(PricingCalculationRow $record): ?string
    {
        return match ($record->finalPriceDifferenceDirection()) {
            'down' => 'heroicon-o-arrow-down',
            'up' => 'heroicon-o-arrow-up',
            default => null,
        };
    }

    private static function differenceColor(PricingCalculationRow $record): ?string
    {
        return match ($record->finalPriceDifferenceDirection()) {
            'down' => 'danger',
            'up' => 'success',
            default => null,
        };
    }

    private static function canEdit(PricingCalculationRow $record): bool
    {
        return Gate::allows('update', $record) && ($record->pricingProject?->isOpenForPricing() ?? false);
    }
}
