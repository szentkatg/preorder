<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase_prices', function (Blueprint $table) {
            $table->id('product_purchase_price_id');

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('color_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('supplier_id');

            $table->foreign('supplier_id')
                ->references('supplier_id')
                ->on('suppliers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('purchase_price', 15, 4);
            $table->boolean('active')->default(true);

            $table->unsignedBigInteger('color_key')->default(0);

            $table->timestamps();

            $table->unique(
                ['product_id', 'supplier_id', 'color_key'],
                'product_supplier_color_unique'
            );

            $table->index(
                ['supplier_id', 'currency_id', 'active'],
                'purchase_prices_supplier_currency_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase_prices');
    }
};