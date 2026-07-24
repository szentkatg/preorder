<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Language;
use App\Models\OrderSheetType;
use App\Traits\HasTranslations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferenceTranslationModelsTest extends TestCase
{
    #[DataProvider('translatableReferenceModels')]
    public function test_reference_model_uses_the_registered_translation_entity(
        string $modelClass,
        string $entity,
    ): void {
        $model = new $modelClass([
            'code' => 'TEST',
            'name' => 'Fallback name',
        ]);

        $this->assertContains(
            HasTranslations::class,
            class_uses_recursive($modelClass),
        );
        $this->assertSame($entity, $model->translationEntity());
        $this->assertSame('TEST', $model->translationEntityCode());
        $this->assertSame('Fallback name', $model->getAttribute('name'));
    }

    public static function translatableReferenceModels(): array
    {
        return [
            'order sheet type' => [OrderSheetType::class, 'order_sheet_type'],
            'currency' => [Currency::class, 'currency'],
            'language' => [Language::class, 'language'],
            'item main group' => [ItemMainGroup::class, 'item_main_group'],
        ];
    }
}
