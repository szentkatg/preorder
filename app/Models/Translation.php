<?php

namespace App\Models;

use App\Services\Translation\TranslationRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    protected $primaryKey = 'translation_id';

    protected $fillable = [
        'entity',
        'entity_code',
        'field',
        'language_id',
        'value',
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function originalValue(): ?string
    {
        if (
            ! TranslationRegistry::isValidEntity($this->entity)
            || ! TranslationRegistry::isValidField(
                $this->entity,
                $this->field
            )
        ) {
            return null;
        }

        $modelClass = TranslationRegistry::model($this->entity);
        $codeColumn = TranslationRegistry::codeColumn($this->entity);

        $record = $modelClass::query()
            ->where($codeColumn, $this->entity_code)
            ->first();

        if ($record === null) {
            return null;
        }

        $fallbackColumn = $this->field === 'name'
            ? TranslationRegistry::fallbackColumn($this->entity)
            : $this->field;

        $value = $this->normalizeValue(
            $record->getAttribute($fallbackColumn)
        );

        if ($value !== null) {
            return $value;
        }

        /*
         * Átmeneti kompatibilitás a régi mezőkkel, amíg a name_hu,
         * name_en, catalog_group_name_hu és catalog_group_name_en
         * oszlopok átvezetése nem készül el.
         */
        foreach ([
            "{$fallbackColumn}_hu",
            "{$fallbackColumn}_en",
        ] as $legacyColumn) {
            $value = $this->normalizeValue(
                $record->getAttribute($legacyColumn)
            );

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}