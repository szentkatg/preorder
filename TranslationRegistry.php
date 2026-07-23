<?php

namespace App\Services\Translation;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\SizeRange;
use InvalidArgumentException;

class TranslationRegistry
{
    /**
     * A rendszerben fordítható entitások központi nyilvántartása.
     *
     * A tömb kulcsa kerül a translations.entity mezőbe.
     */
    public static function all(): array
    {
        return [
            'brand' => [
                'label' => 'Márka',
                'model' => Brand::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'color' => [
                'label' => 'Szín',
                'model' => Color::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'currency' => [
                'label' => 'Pénznem',
                'model' => Currency::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'item_main_group' => [
                'label' => 'Termékfőcsoport',
                'model' => ItemMainGroup::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'language' => [
                'label' => 'Nyelv',
                'model' => Language::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'order_sheet_type' => [
                'label' => 'Rendelési ív típusa',
                'model' => OrderSheetType::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'product' => [
                'label' => 'Termék',
                'model' => Product::class,
                'code_column' => 'model_code',
                'fields' => [
                    'name' => 'Név',
                    'catalog_group_name' => 'Katalóguscsoport neve',
                ],
            ],

            'season' => [
                'label' => 'Szezon',
                'model' => Season::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'size' => [
                'label' => 'Méret',
                'model' => Size::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],

            'size_range' => [
                'label' => 'Méretsor',
                'model' => SizeRange::class,
                'code_column' => 'code',
                'fields' => [
                    'name' => 'Név',
                ],
            ],
        ];
    }

    public static function entityOptions(): array
    {
        return collect(self::all())
            ->mapWithKeys(
                fn (array $configuration, string $entity): array => [
                    $entity => $configuration['label'],
                ]
            )
            ->sort()
            ->all();
    }

    public static function fieldOptions(?string $entity): array
    {
        if (blank($entity)) {
            return [];
        }

        return self::all()[$entity]['fields'] ?? [];
    }

    public static function allFieldOptions(): array
    {
        return collect(self::all())
            ->flatMap(
                fn (array $configuration): array => $configuration['fields']
            )
            ->unique()
            ->sort()
            ->all();
    }

    public static function entityLabel(?string $entity): string
    {
        if (blank($entity)) {
            return '';
        }

        return self::all()[$entity]['label'] ?? $entity;
    }

    public static function fieldLabel(
        ?string $entity,
        ?string $field
    ): string {
        if (blank($field)) {
            return '';
        }

        return self::fieldOptions($entity)[$field] ?? $field;
    }

    public static function model(string $entity): string
    {
        $model = self::all()[$entity]['model'] ?? null;

        if ($model === null) {
            throw new InvalidArgumentException(
                "Ismeretlen fordítási entitás: {$entity}"
            );
        }

        return $model;
    }

    public static function codeColumn(string $entity): string
    {
        $codeColumn = self::all()[$entity]['code_column'] ?? null;

        if ($codeColumn === null) {
            throw new InvalidArgumentException(
                "Az entitáshoz nincs kódmező beállítva: {$entity}"
            );
        }

        return $codeColumn;
    }

    public static function isValidEntity(string $entity): bool
    {
        return array_key_exists($entity, self::all());
    }

    public static function isValidField(
        string $entity,
        string $field
    ): bool {
        return array_key_exists(
            $field,
            self::fieldOptions($entity)
        );
    }
}