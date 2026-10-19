<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('View:PricingWorkflow', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'web')->where('name', 'View:PricingWorkflow')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
