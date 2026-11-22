<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_user_notice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->constrained()->cascadeOnDelete();
            $table->string('notice_key', 100);
            $table->timestamps();
            $table->unique(['partner_user_id', 'notice_key'], 'partner_notice_user_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_user_notice_dismissals');
    }
};
