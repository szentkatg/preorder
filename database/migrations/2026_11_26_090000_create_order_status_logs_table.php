<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_logs', function (Blueprint $table): void {
            $table->id();
            // Retain identity and order snapshots even after the originals are deleted.
            $table->unsignedBigInteger('order_id')->index();
            $table->string('reference_number');
            $table->string('partner_code')->nullable();
            $table->string('partner_name')->nullable();
            $table->string('address_code')->nullable();
            $table->string('season_code')->nullable()->index();
            $table->unsignedBigInteger('partner_user_id')->index();
            $table->string('user_name');
            $table->string('user_email');
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('event', 24)->index();
            $table->string('source', 16);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->dateTime('occurred_at')->index();
            $table->boolean('email_requested');
            $table->string('email_status', 24)->index();
            $table->dateTime('email_finished_at')->nullable();
            $table->string('email_error_type')->nullable();
        });
        Permission::findOrCreate('View:OrderStatusHistory', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_logs');
        Permission::where('guard_name', 'web')->where('name', 'View:OrderStatusHistory')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
