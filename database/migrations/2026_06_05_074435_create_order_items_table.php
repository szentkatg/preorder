<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
        
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
        
            $table->integer('quantity')->default(0);
        
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
        
            $table->timestamps();
        
            $table->unique(['order_id', 'sku_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
