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
    Schema::create('partner_addresses', function (Blueprint $table) {
        $table->id();
    
        $table->foreignId('partner_id')
            ->constrained()
            ->cascadeOnDelete();
    
        $table->string('addrid')->index();
    
        $table->string('name');
    
        $table->string('country', 2)->nullable();
    
        $table->string('zip')->nullable();
        $table->string('city')->nullable();
        $table->string('street')->nullable();
    
        $table->string('contact_name')->nullable();
    
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
    
        $table->boolean('allow_assortment_ordering')
            ->default(false);
    
        $table->unsignedBigInteger('price_list_id')
            ->nullable();
    
        $table->unsignedBigInteger('currency_id')
            ->nullable();
    
        $table->unsignedBigInteger('language_id')
            ->nullable();
    
        $table->boolean('active')
            ->default(true);
    
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_addresses');
    }
};
