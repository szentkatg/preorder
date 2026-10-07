<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_calculation_errors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->foreignId('pricing_project_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('price_type', 40);
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('color_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedBigInteger('product_purchase_price_id')
                ->nullable();
            $table->unsignedBigInteger('supplier_id')
                ->nullable();
            $table->string('severity', 20)->default('error');
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('product_purchase_price_id', 'pricing_errors_purchase_price_fk')
                ->references('product_purchase_price_id')
                ->on('product_purchase_prices')
                ->nullOnDelete();

            $table->foreign('supplier_id', 'pricing_errors_supplier_fk')
                ->references('supplier_id')
                ->on('suppliers')
                ->nullOnDelete();

            $table->index(
                ['pricing_project_id', 'price_type', 'run_id'],
                'pricing_errors_project_type_run_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_calculation_errors');
    }
};
