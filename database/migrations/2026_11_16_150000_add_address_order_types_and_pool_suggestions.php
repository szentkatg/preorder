<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $typeIds = DB::table('order_types')->where('code', 'VRELO')->where('active', true)->pluck('id');
        if ($typeIds->count() !== 1) {
            throw new RuntimeException('Az induló címjogosultságokhoz egyetlen aktív VRELO rendeléstípus szükséges.');
        }
        Schema::create('partner_address_order_type', function (Blueprint $table): void {
            $table->foreignId('partner_address_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['partner_address_id', 'order_type_id']);
        });
        // Existing addresses start with VRELO only, as explicitly requested.
        // New addresses require an explicit administrator/pool selection.
        DB::table('partner_addresses')->orderBy('id')->chunkById(250, function ($addresses) use ($typeIds): void {
            $rows = [];
            foreach ($addresses as $address) {
                foreach ($typeIds as $id) {
                    $rows[] = ['partner_address_id' => $address->id, 'order_type_id' => $id];
                }
            }
            if ($rows !== []) {
                DB::table('partner_address_order_type')->insertOrIgnore($rows);
            }
        });
        Schema::table('partnerpool', fn (Blueprint $table) => $table->json('order_type_codes')->nullable());
    }

    public function down(): void
    {
        Schema::table('partnerpool', fn (Blueprint $table) => $table->dropColumn('order_type_codes'));
        Schema::dropIfExists('partner_address_order_type');
    }
};
