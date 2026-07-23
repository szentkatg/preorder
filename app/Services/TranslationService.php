<?php

namespace App\Services;

use App\Models\Translation;
use App\Services\Translation\TranslationRegistry;
use InvalidArgumentException;

class TranslationService
{
    /**
     * Az egy kérésen belül már feloldott fordítások gyorsítótára.
     *
     * @var array<string, string>
     */
    private array $resolvedTranslations = [];

    public function __construct(
        private readonly LanguageResolver $languageResolver,
    ) {
    }

    public function translate(
        string $entity,
        string $entityCode,
        string $field,
        ?int $languageId = null,
        ?string $fallbackValue = null,
    ): string {
        $this->validateTranslationRequest(
            entity: $entity,
            field: $field,
        );

        $language = $this->languageResolver->resolve($languageId);

        if ($language === null) {
            return $fallbackValue ?? $entityCode;
        }

        $cacheKey = $this->cacheKey(
            entity: $entity,
            entityCode: $entityCode,
            field: $field,
            languageId: (int) $language->getKey(),
        );

        if (array_key_exists($cacheKey, $this->resolvedTranslations)) {
            return $this->resolvedTranslations[$cacheKey];
        }

        $translation = $this->findTranslation(
            entity: $entity,
            entityCode: $entityCode,
            field: $field,
            languageId: (int) $language->getKey(),
        );

        $resolvedValue = $translation
            ?? $fallbackValue
            ?? $entityCode;

        return $this->resolvedTranslations[$cacheKey] = $resolvedValue;
    }

    private function findTranslation(
        string $entity,
        string $entityCode,
        string $field,
        int $languageId,
    ): ?string {
        $value = Translation::query()
            ->where('entity', $entity)
            ->where('entity_code', $entityCode)
            ->where('field', $field)
            ->where('language_id', $languageId)
            ->value('value');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function validateTranslationRequest(
        string $entity,
        string $field,
    ): void {
        if (! TranslationRegistry::isValidEntity($entity)) {
            throw new InvalidArgumentException(
                "Ismeretlen fordítási entitás: {$entity}"
            );
        }

        if (! TranslationRegistry::isValidField($entity, $field)) {
            throw new InvalidArgumentException(
                sprintf(
                    'A(z) %s mező nincs fordítható mezőként regisztrálva a(z) %s entitáshoz.',
                    $field,
                    $entity,
                )
            );
        }
    }

    private function cacheKey(
        string $entity,
        string $entityCode,
        string $field,
        int $languageId,
    ): string {
        return implode('|', [
            $entity,
            $entityCode,
            $field,
            $languageId,
        ]);
    }
}