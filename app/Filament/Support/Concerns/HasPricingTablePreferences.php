<?php

namespace App\Filament\Support\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

trait HasPricingTablePreferences
{
    use HasPricingColumnPreferences;
    use HasPricingWorkflowNavigation;

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
}
