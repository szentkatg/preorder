<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnerpool_sync_state', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('active_batch_id')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('partner_count')->default(0);
            $table->dateTime('source_extracted_at')->nullable();
            $table->dateTime('synced_at')->nullable();
        });
        DB::table('partnerpool_sync_state')->insert(['id' => 1]);

        Schema::create('partnerpool', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('batch_id')->index();
            $table->char('row_key', 64);
            $table->string('erp_partner_code', 50)->index();
            $table->string('partner_name')->index();
            $table->string('short_name')->nullable();
            $table->string('partner_type', 16)->index();
            $table->string('partner_email', 512)->nullable();
            $table->string('partner_phone')->nullable();
            $table->string('sales_rep_erp_partner_code', 50)->nullable();
            $table->boolean('partner_active')->default(true);
            $table->string('addrid', 100)->nullable()->index();
            $table->string('address_name')->nullable();
            $table->string('country', 64)->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->text('street')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('address_email', 512)->nullable();
            $table->string('address_phone')->nullable();
            $table->boolean('address_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('currency_code', 3)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('price_list_code', 100)->nullable();
            $table->dateTime('source_extracted_at');
            $table->unique(['batch_id', 'row_key'], 'partnerpool_batch_row_unique');
        });

        Schema::create('partnerpool_import_logs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('source_batch_id');
            $table->char('source_row_key', 64);
            $table->string('erp_partner_code', 50)->index();
            $table->string('addrid', 100)->nullable();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('partner_address_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->json('created_records');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnerpool_import_logs');
        Schema::dropIfExists('partnerpool');
        Schema::dropIfExists('partnerpool_sync_state');
    }
};
