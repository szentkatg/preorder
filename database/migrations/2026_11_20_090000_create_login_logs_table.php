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
        Schema::create('login_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('guard', 16);
            // Two separate user tables: retain identity snapshots after deletion.
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('user_email');
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('logged_in_at')->index();
            $table->index(['guard', 'user_id', 'logged_in_at']);
            $table->index('ip_address');
        });

        Permission::findOrCreate('View:LoginHistory', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
        Permission::where('guard_name', 'web')->where('name', 'View:LoginHistory')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
