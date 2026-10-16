<?php

namespace App\Filament\Support\Concerns;

use App\Models\AdminUiPreference;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Livewire\Partials\PartialsComponentHook;
use Filament\Tables\Columns\Column;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

trait HasPricingTablePreferences
{
    /**
     * @var array<string, int>
     */
    public array $pricingColumnWidths = [];

    /** @var array<string, int> */
    #[Locked]
    public array $pricingDecimalPlaces = [];

    /** @return array<string, string> */
    abstract protected function pricingDecimalColumns(): array;

    protected function pricingDecimalPlacesAction(): Action
    {
        $fields = [];
        foreach ($this->pricingDecimalColumns() as $name => $label) {
            $fields[] = Select::make($name)->label($label)
                ->options(array_combine(range(0, 6), array_map(fn (int $digits): string => $digits.' tizedesjegy', range(0, 6))))
                ->placeholder('Alapértelmezett')->nullable();
        }

        return Action::make('decimalPlaces')->label('Tizedesjegyek')
            ->icon('heroicon-o-adjustments-horizontal')->color('gray')
            ->modalHeading('Tizedesjegyek beállítása')->modalWidth(Width::ThreeExtraLarge)
            ->modalDescription('Oszloponként, csak a kijelzés és az Excel-formátum változik. A pontos értékek, számítások és szerkeszthető beviteli mezők változatlanok. A beállítást felhasználónként megjegyezzük.')
            ->schema([Grid::make(2)->schema($fields)])
            ->fillForm(fn (): array => array_replace(array_fill_keys(array_keys($this->pricingDecimalColumns()), null), $this->pricingDecimalPlaces))
            ->modalSubmitActionLabel('Mentés')->modalCancelActionLabel('Mégse')
            ->action(fn (array $data) => $this->savePricingDecimalPlaces($data));
    }

    public function savePricingDecimalPlaces(array $places): void
    {
        abort_unless(auth()->id() && static::canAccess(), 403);
        $rules = array_fill_keys(array_keys($this->pricingDecimalColumns()), ['nullable', 'integer', 'min:0', 'max:6']);
        $validated = Validator::make($places, $rules)->validate();
        $this->pricingDecimalPlaces = $this->sanitizePricingDecimalPlaces($validated);
        $key = $this->pricingPreferencePrefix().'_decimal_places';
        $saved = $this->loadAdminPreference($key);
        // Outlet columns depend on project/currency. Keep preferences for other contexts.
        $otherColumns = is_array($saved) ? array_diff_key($saved, $rules) : [];
        $this->saveAdminPreference($key, array_replace($otherColumns, $this->pricingDecimalPlaces));
    }

    public function pricingDecimalPlacesFor(string $column): ?int
    {
        return $this->pricingDecimalPlaces[$column] ?? null;
    }

    public function formatPricingDecimal(mixed $state, string $column, int $defaultDecimals = 4, bool $trimDefaultZeros = true, string $thousandsSeparator = ' '): string
    {
        if ($state === null) {
            return '-';
        }

        $decimals = $this->pricingDecimalPlacesFor($column);
        $formatted = number_format((float) $state, $decimals ?? $defaultDecimals, ',', $thousandsSeparator);

        return $decimals === null && $trimDefaultZeros && $defaultDecimals > 0
            ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    /**
     * @param  array<Column>  $columns
     * @return array<string, string>
     */
    public function pricingDecimalExportFormats(array $columns): array
    {
        $formats = [];
        foreach (array_values($columns) as $index => $column) {
            $digits = $this->pricingDecimalPlacesFor($column->getName());
            if ($digits !== null) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = '#,##0'.($digits > 0 ? '.'.str_repeat('0', $digits) : '');
            }
        }

        return $formats;
    }

    protected function loadPricingDecimalPlaces(): array
    {
        $saved = $this->loadAdminPreference($this->pricingPreferencePrefix().'_decimal_places');

        return $this->sanitizePricingDecimalPlaces(is_array($saved) ? $saved : []);
    }

    private function sanitizePricingDecimalPlaces(array $places): array
    {
        $sanitized = [];
        foreach ($this->pricingDecimalColumns() as $column => $label) {
            $digits = $places[$column] ?? null;
            if ((is_int($digits) && $digits >= 0 && $digits <= 6)
                || (is_string($digits) && preg_match('/^[0-6]$/D', $digits))) {
                $sanitized[$column] = (int) $digits;
            }
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $widths
     */
    public function savePricingColumnWidths(array $widths): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        $this->pricingColumnWidths = $this->sanitizePricingColumnWidths($widths);

        AdminUiPreference::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'preference_key' => $this->pricingColumnWidthPreferenceKey(),
            ],
            [
                'value' => $this->pricingColumnWidths,
            ],
        );

        // The browser already resized the columns. No table HTML is needed.
        // Unlike skipRender(), this still permits a full render for other bundled changes.
        app(PartialsComponentHook::class)->skipPartialRender($this);
    }

    public function resetPricingColumnWidths(): void
    {
        $userId = auth()->id();

        $this->pricingColumnWidths = [];

        if (! $userId) {
            return;
        }

        AdminUiPreference::query()
            ->where('user_id', $userId)
            ->where('preference_key', $this->pricingColumnWidthPreferenceKey())
            ->delete();

        app(PartialsComponentHook::class)->skipPartialRender($this);
    }

    abstract protected function pricingPreferencePrefix(): string;

    public function pricingColumnStorageKey(): string
    {
        return str_replace('_', '-', $this->pricingPreferencePrefix()).'-column-widths-v2-user-'.(auth()->id() ?? 'guest');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadTableColumnsFromSession(): array
    {
        $hasReordered = $this->loadAdminPreference($this->tableHasReorderedColumnsPreferenceKey());

        if (is_bool($hasReordered)) {
            session()->put($this->getHasReorderedTableColumnsSessionKey(), $hasReordered);
        }

        $columns = $this->loadAdminPreference($this->tableColumnsPreferenceKey());

        if (is_array($columns)) {
            return $columns;
        }

        return session()->get(
            $this->getTableColumnsSessionKey(),
            $this->getDefaultTableColumnState(),
        );
    }

    protected function persistTableColumns(): void
    {
        if (! $this->getTable()->persistsColumnsInSession()) {
            return;
        }

        session()->put(
            $this->getTableColumnsSessionKey(),
            $this->tableColumns
        );

        $this->saveAdminPreference($this->tableColumnsPreferenceKey(), $this->tableColumns);
    }

    protected function persistHasReorderedTableColumns(bool $wasReordered = false): void
    {
        $hasReordered = $wasReordered || $this->hasReorderedTableColumns();

        session()->put(
            $this->getHasReorderedTableColumnsSessionKey(),
            $hasReordered
        );

        $this->saveAdminPreference($this->tableHasReorderedColumnsPreferenceKey(), $hasReordered);
    }

    /**
     * @return array<string, int>
     */
    private function loadPricingColumnWidths(): array
    {
        $userId = auth()->id();

        if (! $userId) {
            return [];
        }

        $preference = AdminUiPreference::query()
            ->where('user_id', $userId)
            ->where('preference_key', $this->pricingColumnWidthPreferenceKey())
            ->first();

        return $this->sanitizePricingColumnWidths(is_array($preference?->value) ? $preference->value : []);
    }

    /**
     * @param  array<string, mixed>  $widths
     * @return array<string, int>
     */
    private function sanitizePricingColumnWidths(array $widths): array
    {
        $sanitized = [];

        foreach ($widths as $column => $width) {
            if (! is_string($column) || ! is_numeric($width)) {
                continue;
            }

            $width = (int) round((float) $width);

            if ($width < 72 || $width > 2000) {
                continue;
            }

            $sanitized[$column] = $width;
        }

        return $sanitized;
    }

    private function pricingColumnWidthPreferenceKey(): string
    {
        return $this->pricingPreferencePrefix().'_column_widths';
    }

    private function tableColumnsPreferenceKey(): string
    {
        return $this->pricingPreferencePrefix().'_table_columns';
    }

    private function tableHasReorderedColumnsPreferenceKey(): string
    {
        return $this->pricingPreferencePrefix().'_has_reordered_columns';
    }

    private function loadAdminPreference(string $key): mixed
    {
        $userId = auth()->id();

        if (! $userId) {
            return null;
        }

        return AdminUiPreference::query()
            ->where('user_id', $userId)
            ->where('preference_key', $key)
            ->first()
            ?->value;
    }

    private function saveAdminPreference(string $key, mixed $value): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        AdminUiPreference::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'preference_key' => $key,
            ],
            [
                'value' => $value,
            ],
        );
    }
}
