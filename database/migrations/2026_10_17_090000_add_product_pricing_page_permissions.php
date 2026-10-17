<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['View:PricingProductOverview', 'View:PricingProductWorkspace'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Keep published/closed projects untouched. Existing outlet approvals
        // must be reviewed against the new minimum-discount-safe rounding.
        DB::table('pricing_calculation_rows')->where('price_type', 'outlet')
            ->whereIn('pricing_project_id', DB::table('pricing_projects')->whereIn('status', ['draft', 'active'])->select('id'))
            ->update(['requires_review' => true]);
    }

    public function down(): void
    {
        Permission::where('guard_name', 'web')->whereIn('name', ['View:PricingProductOverview', 'View:PricingProductWorkspace'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
