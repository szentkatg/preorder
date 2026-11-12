<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partnerpool', function (Blueprint $table): void {
            $table->json('brand_codes')->nullable();
            $table->json('order_sheet_type_codes')->nullable();
            $table->text('address_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partnerpool', fn (Blueprint $table) => $table->dropColumn([
            'brand_codes', 'order_sheet_type_codes', 'address_type',
        ]));
    }
};
