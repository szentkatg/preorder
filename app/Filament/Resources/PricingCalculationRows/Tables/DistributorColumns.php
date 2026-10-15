<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorPriceReviewService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Support\Facades\Gate;

class DistributorColumns
{
    public static function make(): array
    {
        $columns = [
            TextColumn::make('distributor_discount_percent')->label('Disztr. kedvezmény %')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->distributorRow($record)?->distributor_discount_percent)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_discount_percent', 6, true, '')),
            TextColumn::make('distributor_calculated_price')->label('Kalkulált disztr. EUR')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->distributorRow($record)?->calculated_price)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_calculated_price')),
            TextInputColumn::make('distributor_manual_price')->label('Manuális disztr. EUR')->type('text')->inputMode('decimal')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->distributorRow($record)?->manual_price === null
                    ? null : (float) $livewire->distributorRow($record)->manual_price)
                ->extraInputAttributes(fn (PricingCalculationRow $record, $livewire) => ['class' => 'pricing-retail-manual-input pricing-retail-manual-'.(self::row($record, $livewire)?->finalPriceDifferenceDirection() ?? 'equal')])
                ->suffixIcon(fn (PricingCalculationRow $record, $livewire) => self::icon($record, $livewire), true)
                ->suffixIconColor(fn (PricingCalculationRow $record, $livewire) => self::color($record, $livewire))
                ->rules(['nullable', 'numeric', 'gt:0', 'max:99999999999.9999'])
                ->disabled(fn (PricingCalculationRow $record, $livewire) => ! $livewire->pricingWorkspaceIsEditable()
                    || ! ($row = self::row($record, $livewire)) || Gate::denies('update', $row))
                ->tooltip('Önálló nettó disztribútori manuális ár. Üres mezővel a kalkulált ár áll vissza. A nagyker árat és jóváhagyást nem módosítja.')
                ->updateStateUsing(function (PricingCalculationRow $record, mixed $state, $livewire) {
                    $row = $livewire->distributorRow($record, fresh: true);
                    abort_unless($row, 404);
                    try {
                        $updated = app(DistributorPriceReviewService::class)->setManualPrice($row, $state);

                        return $updated->manual_price === null ? null : (float) $updated->manual_price;
                    } catch (DomainException $exception) {
                        Notification::make()->title('A disztribútori kalkuláció nem aktuális')->body($exception->getMessage())->warning()->send();

                        return ['error' => $exception->getMessage()];
                    } finally {
                        unset($livewire->averageDistributorNetNetMultiplier);
                        $livewire->resetTable();
                    }
                }),
            TextColumn::make('distributor_final_price')->label('Végleges disztr. EUR')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->final_price)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_final_price'))
                ->icon(fn (PricingCalculationRow $record, $livewire) => self::icon($record, $livewire))
                ->color(fn (PricingCalculationRow $record, $livewire) => self::color($record, $livewire))
                ->iconColor(fn (PricingCalculationRow $record, $livewire) => self::color($record, $livewire)),
            TextColumn::make('distributor_net_net_multiplier')->label('Disztr. nettó/nettó szorzó')->numeric()->placeholder('-')
                ->getStateUsing(function (PricingCalculationRow $record, $livewire): ?float {
                    $price = self::row($record, $livewire)?->final_price;
                    $vat = $livewire->wholesaleVatPercent();

                    return (float) $price > 0 && (float) $record->base_price > 0 && $vat !== null
                        ? (float) $record->base_price / (1 + $vat / 100) / (float) $price : null;
                })
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_net_net_multiplier', 6, true, ''))
                ->extraHeaderAttributes(fn ($livewire) => ['data-pricing-average-multiplier' => 'Átlag: '.($livewire->averageDistributorNetNetMultiplier === null
                    ? '-' : number_format($livewire->averageDistributorNetNetMultiplier, 2, ',', ' '))]),
            IconColumn::make('distributor_requires_review')->label('Disztr. felülvizsgálandó')->boolean()->trueColor('danger')->falseColor('success')->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->requires_review),
            TextColumn::make('distributor_status')->label('Disztr. státusz')->badge()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->status)
                ->formatStateUsing(fn ($state) => match ($state) {
                    'calculated' => 'Kalkulált ár', 'reviewed' => 'Átnézett ár', 'approved' => 'Jóváhagyott ár',
                    'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott', 'approved_round_3' => '3. körös jóváhagyott', default => $state,
                }),
        ];
        foreach ($columns as $column) {
            $column->extraAttributes(['data-pricing-distributor-column' => true]);
        }

        return $columns;
    }

    private static function row(PricingCalculationRow $record, $livewire): ?PricingCalculationRow
    {
        return $livewire->distributorRow($record);
    }

    private static function icon(PricingCalculationRow $record, $livewire): ?string
    {
        $direction = self::row($record, $livewire)?->finalPriceDifferenceDirection();

        return $direction ? 'heroicon-o-arrow-'.$direction : null;
    }

    private static function color(PricingCalculationRow $record, $livewire): ?string
    {
        return match (self::row($record, $livewire)?->finalPriceDifferenceDirection()) {
            'down' => 'danger', 'up' => 'success', default => null,
        };
    }
}
