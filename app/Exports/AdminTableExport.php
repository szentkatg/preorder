<?php

namespace App\Exports;

use BackedEnum;
use DateTimeInterface;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Stringable;

class AdminTableExport extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  array<Column>  $columns
     */
    public function __construct(
        protected Builder $query,
        protected array $columns,
        protected array $columnFormats = [],
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function columnFormats(): array
    {
        return $this->columnFormats;
    }

    public function headings(): array
    {
        return array_map(
            fn (Column $column): string => $this->stringValue($column->getLabel()),
            $this->columns,
        );
    }

    public function map($record): array
    {
        return array_map(
            function (Column $column) use ($record): mixed {
                $column = clone $column;
                $column->record($record);
                $column->clearCachedState();

                $value = $column->getState();

                // Decimal casts return strings. Export them as actual numbers so
                // Excel can use the viewer's system separators and calculations.
                // Do not infer numeric types from the value alone: codes are text.
                if (is_string($value) && is_numeric($value) && $this->isNumericColumn($column, $record)) {
                    return (float) $value;
                }

                return $this->exportValue($value);
            },
            $this->columns,
        );
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function isNumericColumn(Column $column, Model $record): bool
    {
        if (($column instanceof TextColumn) && ($column->isNumeric() || $column->isMoney())) {
            return true;
        }

        $path = explode('.', $column->getName());
        $attribute = array_pop($path);
        $owner = $path === [] ? $record : data_get($record, implode('.', $path));

        return ($owner instanceof Model) && $owner->hasCast(
            $attribute,
            ['decimal', 'float', 'double', 'real', 'integer', 'int'],
        );
    }

    private function exportValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Htmlable) {
            return trim(strip_tags($value->toHtml()));
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => $this->stringValue($item))
                ->implode(', ');
        }

        if (is_bool($value)) {
            return $value ? 'Igen' : 'Nem';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $value;
    }

    private function stringValue(mixed $value): string
    {
        return (string) $this->exportValue($value);
    }
}
