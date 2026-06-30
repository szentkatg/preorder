<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_code')->nullable()->index();
            $table->string('filename');
            $table->string('status')->default('success')->index();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_import_logs');
    }
};
