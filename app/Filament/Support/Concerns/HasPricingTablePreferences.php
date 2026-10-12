<?php

namespace App\Filament\Support\Concerns;

use App\Models\AdminUiPreference;

trait HasPricingTablePreferences
{
    /**
     * @var array<string, int>
     */
    public array $pricingColumnWidths = [];

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
