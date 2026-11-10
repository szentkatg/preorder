<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('View:ErpPartnerPool', 'web');
        Permission::findOrCreate('Import:PartnerPool', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'web')->whereIn('name', ['View:ErpPartnerPool', 'Import:PartnerPool'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
