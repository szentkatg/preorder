<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id('translation_id');

            $table->string('entity', 50);
            $table->string('entity_code', 100);
            $table->string('field', 50);

            $table->foreignId('language_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->text('value');

            $table->timestamps();

            $table->unique(
                ['entity', 'entity_code', 'field', 'language_id'],
                'translations_unique'
            );

            $table->index(['entity', 'entity_code']);
            $table->index('language_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};