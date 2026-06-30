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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
        
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_address_id')->constrained()->cascadeOnDelete();
        
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_sheet_type_id')->constrained()->restrictOnDelete();
        
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
        
            $table->string('status')->default('editing');
        
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
        
            $table->timestamps();
        
            $table->index([
                'season_id',
                'partner_address_id',
                'brand_id',
                'order_sheet_type_id',
            ], 'orders_context_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
