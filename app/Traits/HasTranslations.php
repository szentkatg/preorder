<?php

namespace App\Traits;

use App\Services\Translation\TranslationRegistry;
use App\Services\TranslationService;
use Illuminate\Support\Str;
use LogicException;

trait HasTranslations
{
    public function translate(
        string $field,
        ?int $languageId = null,
    ): string {
        $entity = $this->translationEntity();

        return app(TranslationService::class)->translate(
            entity: $entity,
            entityCode: $this->translationEntityCode(),
            field: $field,
            languageId: $languageId,
            fallbackValue: $this->translationFallbackValue(
                entity: $entity,
                field: $field,
            ),
        );
    }

    public function translationEntity(): string
    {
        $entity = Str::snake(class_basename($this));

        if (! TranslationRegistry::isValidEntity($entity)) {
            throw new LogicException(
                sprintf(
                    'A(z) %s modell nincs regisztrálva a TranslationRegistry-ben.',
                    static::class,
                )
            );
        }

        return $entity;
    }

    public function translationEntityCode(): string
    {
        $entity = $this->translationEntity();
        $codeColumn = TranslationRegistry::codeColumn($entity);
        $code = $this->getAttribute($codeColumn);

        if (! is_scalar($code) || trim((string) $code) === '') {
            throw new LogicException(
                sprintf(
                    'A(z) %s modell %s mezője nem lehet üres a fordítás használatához.',
                    static::class,
                    $codeColumn,
                )
            );
        }

        return trim((string) $code);
    }

    private function translationFallbackValue(
        string $entity,
        string $field,
    ): ?string {
        $fallbackColumn = $field === 'name'
            ? TranslationRegistry::fallbackColumn($entity)
            : $field;

        $value = $this->normalizedTranslationValue(
            $this->getAttribute($fallbackColumn)
        );

        if ($value !== null) {
            return $value;
        }

        /*
         * Átmeneti kompatibilitás a régi name_hu/name_en,
         * catalog_group_name_hu/catalog_group_name_en stb. mezőkkel.
         *
         * Ezek a régi oszlopok az átállás befejezése után eltávolíthatók.
         */
        foreach ([
            "{$fallbackColumn}_hu",
            "{$fallbackColumn}_en",
        ] as $legacyColumn) {
            $value = $this->normalizedTranslationValue(
                $this->getAttribute($legacyColumn)
            );

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizedTranslationValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}