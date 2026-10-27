<?php

namespace App\Filament\Support;

use App\Models\PricingCalculationRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;

class OutletColumns
{
    public static function targetPriceTooltip(PricingCalculationRow $source, int $listId): ?string
    {
        $row = self::row($source, $listId);
        if (! $row || $row->outlet_discount_percent === null || $source->final_price === null) {
            return null;
        }
        $target = BigDecimal::of($source->final_price)->multipliedBy(
            BigDecimal::one()->minus(BigDecimal::of($row->outlet_discount_percent)->dividedBy(100, 8, RoundingMode::HALF_UP)),
        );
        $format = fn ($value) => rtrim(rtrim(number_format((float) (string) $value, 4, ',', ' '), '0'), ',');

        return 'Elvárt outlet ár kerekítés nélkül: '.$format($target).' '.($source->currency?->code ?? '')
            .' (kisker ár: '.$format($source->final_price).', kedvezmény: '.$format($row->outlet_discount_percent).'%).'
            .($row->requires_review ? ' Az outlet kalkuláció felülvizsgálandó; az érték az aktuális kisker árból számolt tájékoztatás.' : '');
    }

    public static function row(PricingCalculationRow $source, int $listId): ?PricingCalculationRow
    {
        return $source->outletRows->first(fn ($row) => $row->price_list_id == $listId
            && $row->pricing_project_id == $source->pricing_project_id && $row->product_id == $source->product_id
            && $row->color_key == $source->color_key && $row->currency_id == $source->currency_id);
    }

    public static function make($page): array
    {
        $columns = [];
        foreach ($page->outletPriceLists as $list) {
            $id = $list->id;
            foreach (['discount_percent' => 'Min. kedvezmény %', 'actual_discount' => 'Tényleges kedvezmény %', 'calculated_price' => 'Kalkulált', 'manual_price' => 'Manuális', 'final_price' => 'Végleges', 'requires_review' => 'Felülvizsgálandó', 'status' => 'Státusz'] as $field => $label) {
                $name = 'outlet_'.$id.'_'.$field;
                $column = TextColumn::make($name)->label($list->code.' – '.$label)
                    ->extraAttributes(['data-pricing-outlet-column' => true])->placeholder('—')
                    ->getStateUsing(function (PricingCalculationRow $record, $livewire) use ($id, $field) {
                        $row = self::row($record, $id);

                        return match ($field) {
                            'discount_percent' => $row?->outlet_discount_percent,
                            'actual_discount' => $row?->actual_outlet_discount,
                            default => $row?->{$field},
                        };
                    })
                    ->formatStateUsing(fn ($state, $livewire) => match ($field) {
                        'requires_review' => $state ? 'Igen' : 'Nem',
                        'status' => match ($state) {
                            'calculated' => 'Kalkulált ár', 'reviewed' => 'Átnézett ár', 'approved' => 'Jóváhagyott ár',
                            'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott', 'approved_round_3' => '3. körös jóváhagyott', default => $state,
                        },
                        default => $livewire->formatPricingDecimal($state, $name, 2),
                    });
                if (in_array($field, ['actual_discount', 'final_price'], true)) {
                    $column->color(fn (PricingCalculationRow $record) => ($row = self::row($record, $id)) && ! $row->outletDiscountIsSufficient() ? 'danger' : null)
                        ->tooltip('A tényleges kedvezmény nem lehet kisebb a minimum kedvezménynél. Piros eltérésnél az outlet ár nem hagyható jóvá.');
                }
                if (! in_array($field, ['requires_review', 'status'], true)) {
                    $column->numeric()->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, $name, 2));
                }
                if (in_array($field, ['discount_percent', 'actual_discount', 'manual_price'], true)) {
                    $column->tooltip(fn (PricingCalculationRow $record) => self::targetPriceTooltip($record, $id))
                        ->extraCellAttributes(fn (PricingCalculationRow $record) => [
                            'title' => self::targetPriceTooltip($record, $id),
                        ], merge: true);
                }
                if ($field === 'requires_review') {
                    $column->color(fn ($state) => $state ? 'danger' : 'success');
                }
                if ($field === 'manual_price') {
                    $column->extraCellAttributes(fn (PricingCalculationRow $record, $livewire) => [
                        'data-pricing-editable' => $livewire->canEditPricingGridRow($record) && ($row = self::row($record, $id)) && Gate::allows('update', $row) ? 'true' : 'false',
                        'data-pricing-record-id' => $record->id, 'data-pricing-outlet-list-id' => $id,
                        'data-pricing-value' => PricingGrid::inputValue(self::row($record, $id)?->manual_price),
                        'data-pricing-label' => $list->code.' manuális ár', 'tabindex' => 0,
                        'title' => (self::targetPriceTooltip($record, $id) ?? '').' Dupla kattintás vagy Enter/F2: szerkesztés. Üres érték: manuális ár törlése.',
                    ]);
                }
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
