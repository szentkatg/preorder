<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AdminTableState
{
    public static function configure(Table $table): void
    {
        $livewire = $table->getLivewire();
        if (Filament::getCurrentPanel()?->getId() !== 'admin'
            || (! $livewire instanceof Page && ! $livewire instanceof RelationManager && ! $livewire instanceof TableWidget)) {
            return;
        }

        $table->persistFiltersInSession()->persistSearchInSession()
            ->persistColumnSearchesInSession()->persistSortInSession()
            ->filtersApplyAction(fn (Action $action): Action => $table->getFiltersLayout() === FiltersLayout::Dropdown
                ? $action->alpineClickHandler('await $wire.applyTableFilters(); close()') : $action);
    }
}
