<?php

namespace App\Traits;

use App\Services\TranslationService;
use Illuminate\Support\Str;
use LogicException;

trait HasTranslations
{
    public function translate(
        string $field,
        ?int $languageId = null,
    ): string {
        return app(TranslationService::class)->translate(
            entity: $this->translationEntity(),
            entityCode: $this->translationEntityCode(),
            field: $field,
            languageId: $languageId,
        );
    }

    public function translationEntity(): string
    {
        return Str::snake(class_basename($this));
    }

    public function translationEntityCode(): string
    {
        $code = $this->getAttribute('code');

        if (! is_string($code) || trim($code) === '') {
            throw new LogicException(
                sprintf(
                    '%s must have a non-empty code attribute to use HasTranslations.',
                    static::class,
                )
            );
        }

        return $code;
    }
}