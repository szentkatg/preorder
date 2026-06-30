<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class GenericTableExport extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, WithCustomValueBinder
{
    protected array $columns;

    protected array $relations;

    protected array $stringColumns;

    protected ?string $orderBy;

    public function __construct(
        protected string $modelClass,
    ) {
        $this->columns = method_exists($modelClass, 'exportColumns')
            ? $modelClass::exportColumns()
            : [];

        $this->relations = method_exists($modelClass, 'exportRelations')
            ? $modelClass::exportRelations()
            : [];

        $this->stringColumns = method_exists($modelClass, 'exportStringColumns')
            ? $modelClass::exportStringColumns()
            : [];

        $this->orderBy = method_exists($modelClass, 'exportOrderBy')
            ? $modelClass::exportOrderBy()
            : 'id';
    }

    public function query(): Builder
    {
        $query = $this->modelClass::query();

        if ($this->relations) {
            $query->with($this->relations);
        }

        if ($this->orderBy) {
            $query->orderBy($this->orderBy);
        }

        return $query;
    }

    public function headings(): array
    {
        return collect($this->columns)
            ->map(fn ($config, $key) => is_array($config) ? $config['label'] : $config)
            ->values()
            ->all();
    }

    public function map($record): array
    {
        return collect($this->columns)
            ->map(function ($config, $key) use ($record) {
                if (is_array($config) && isset($config['value']) && is_callable($config['value'])) {
                    return $config['value']($record);
                }

                return data_get($record, $key);
            })
            ->values()
            ->all();
    }

    public function bindValue(Cell $cell, $value): bool
    {
        $columnIndex = Coordinate::columnIndexFromString($cell->getColumn()) - 1;

        $field = array_keys($this->columns)[$columnIndex] ?? null;

        if ($field && in_array($field, $this->stringColumns, true) && $cell->getRow() > 1) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}