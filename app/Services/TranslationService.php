<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Translation;

class TranslationService
{
    /**
     * Az egy kérésen belül már lekért fordítások gyorsítótára.
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
    ): string {
        $language = $this->languageResolver->resolve($languageId);

        if ($language === null) {
            return $entityCode;
        }

        $cacheKey = $this->cacheKey(
            entity: $entity,
            entityCode: $entityCode,
            field: $field,
            languageId: $language->getKey(),
        );

        if (array_key_exists($cacheKey, $this->resolvedTranslations)) {
            return $this->resolvedTranslations[$cacheKey];
        }

        $value = $this->findTranslation(
            entity: $entity,
            entityCode: $entityCode,
            field: $field,
            languageId: $language->getKey(),
        );

        if ($value === null && $language->code !== LanguageResolver::DEFAULT_LANGUAGE_CODE) {
            $value = $this->findDefaultLanguageTranslation(
                entity: $entity,
                entityCode: $entityCode,
                field: $field,
            );
        }

        return $this->resolvedTranslations[$cacheKey] = $value ?? $entityCode;
    }

    private function findTranslation(
        string $entity,
        string $entityCode,
        string $field,
        int $languageId,
    ): ?string {
        return Translation::query()
            ->where('entity', $entity)
            ->where('entity_code', $entityCode)
            ->where('field', $field)
            ->where('language_id', $languageId)
            ->value('value');
    }

    private function findDefaultLanguageTranslation(
        string $entity,
        string $entityCode,
        string $field,
    ): ?string {
        $defaultLanguageId = Language::query()
            ->where('code', LanguageResolver::DEFAULT_LANGUAGE_CODE)
            ->where('active', true)
            ->value('id');

        if ($defaultLanguageId === null) {
            return null;
        }

        return $this->findTranslation(
            entity: $entity,
            entityCode: $entityCode,
            field: $field,
            languageId: (int) $defaultLanguageId,
        );
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