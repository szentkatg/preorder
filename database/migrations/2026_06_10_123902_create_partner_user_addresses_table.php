<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->constrained('partner_users')->cascadeOnDelete();
            $table->foreignId('partner_address_id')->constrained('partner_addresses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_user_id', 'partner_address_id'], 'partner_user_address_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_user_addresses');
    }
};