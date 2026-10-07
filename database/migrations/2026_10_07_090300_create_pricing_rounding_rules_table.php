<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rounding_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('price_type', 40);
            $table->foreignId('price_list_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->decimal('round_to', 15, 4)->nullable();
            $table->unsignedTinyInteger('decimal_places')->nullable();
            $table->string('mode', 20)->default('nearest');
            $table->decimal('adjustment', 15, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(
                ['pricing_project_id', 'price_type', 'price_list_id', 'currency_id', 'active'],
                'pricing_rounding_rules_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rounding_rules');
    }
};
