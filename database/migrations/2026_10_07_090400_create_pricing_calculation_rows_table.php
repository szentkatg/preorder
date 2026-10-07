<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_calculation_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('color_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('color_key')->default(0);
            $table->foreignId('price_list_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('price_list_key')->default(0);
            $table->string('price_type', 40);
            $table->foreignId('currency_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->decimal('calculated_price', 15, 4)->nullable();
            $table->decimal('manual_price', 15, 4)->nullable();
            $table->decimal('final_price', 15, 4)->nullable();
            $table->string('status', 40)->default('calculated');
            $table->json('calculation_snapshot')->nullable();
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['pricing_project_id', 'product_id', 'color_key', 'price_list_key', 'price_type', 'currency_id'],
                'pricing_calculation_rows_unique'
            );
            $table->index(
                ['pricing_project_id', 'price_type', 'status'],
                'pricing_calculation_rows_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_calculation_rows');
    }
};
