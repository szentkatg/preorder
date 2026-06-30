<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_address_order_sheet_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_address_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_sheet_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_address_id', 'order_sheet_type_id'], 'partner_address_sheet_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_address_order_sheet_type');
    }
};