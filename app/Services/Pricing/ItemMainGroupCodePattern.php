<?php

namespace App\Services\Pricing;

use App\Models\ItemMainGroup;
use DomainException;

class ItemMainGroupCodePattern
{
    public const VALIDATION_REGEX = '/^(?=.*[*?])[\p{L}\p{N}_.\/?*\-]+$/u';

    public static function normalize(string $pattern): string
    {
        $pattern = preg_replace('/\*+/', '*', mb_strtoupper(trim($pattern)));
        if (mb_strlen($pattern) > 100 || ! preg_match(self::VALIDATION_REGEX, $pattern)) {
            throw new DomainException('Érvénytelen főcsoportkód-minta. Használj * vagy ? jokert, például S* vagy S0?. Szóköz nem adható meg.');
        }

        return $pattern;
    }

    public static function matches(string $pattern, ?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }
        $quoted = preg_quote(self::normalize($pattern), '~');

        return (bool) preg_match('~^'.strtr($quoted, ['\\*' => '.*', '\\?' => '.']).'$~iuD', $code);
    }

    /** More literal characters, then more one-character jokers, then fewer stars are more specific. */
    public static function specificity(string $pattern): array
    {
        $pattern = self::normalize($pattern);

        return [mb_strlen(str_replace(['*', '?'], '', $pattern)), substr_count($pattern, '?'), -substr_count($pattern, '*')];
    }

    public static function groupIds(string $pattern): array
    {
        return ItemMainGroup::query()->get(['id', 'code'])
            ->filter(fn ($group) => self::matches($pattern, $group->code))->modelKeys();
    }
}
