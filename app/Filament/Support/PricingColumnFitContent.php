<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class PricingColumnFitContent
{
    /** Return distinct displayed texts, not full models or table HTML. */
    public static function collect(HasTable $livewire): array
    {
        $columns = array_filter($livewire->getTable()->getVisibleColumns(), fn ($column) => $column instanceof TextColumn);
        $result = [];
        foreach ($columns as $name => $column) {
            $key = (string) str($name)->camel()->kebab();
            $result[$key] = ['texts' => [], 'badge' => false, 'icon' => false];
        }

        // Includes filters, individual/global searches, relationship counts,
        // eager loading and the page's project/currency scope, but no pagination.
        $query = clone $livewire->getFilteredTableQuery();
        foreach ($query->reorder()->lazyById(200) as $record) {
            foreach ($columns as $name => $configuredColumn) {
                $column = clone $configuredColumn;
                $column->clearCachedState();
                $column->record($record);
                $key = (string) str($name)->camel()->kebab();
                $state = $column->getState();
                $result[$key]['badge'] = $result[$key]['badge'] || $column->isBadge();
                $items = $state instanceof Collection ? $state->all() : (is_array($state) ? $state : [$state]);
                if (blank($state)) {
                    $texts = [self::text($column->getPlaceholder())];
                } else {
                    $texts = [];
                    foreach ($items as $item) {
                        $texts[] = self::text($column->formatState($item));
                        $result[$key]['icon'] = $result[$key]['icon'] || filled($column->getIcon($item));
                    }
                    if (! $column->isBadge() && ! $column->isListWithLineBreaks()) {
                        $texts = [implode(', ', $texts)];
                    }
                }
                foreach ($texts as $text) {
                    // A value is stored only once even when hundreds of models share it.
                    $result[$key]['texts'][$text] = $text;
                }
            }
        }
        foreach ($result as &$column) {
            $column['texts'] = array_values($column['texts']);
        }

        return $result;
    }

    private static function text(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return html_entity_decode(strip_tags($value->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return (string) ($value ?? '');
    }
}
