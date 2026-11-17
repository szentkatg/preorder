<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_portal_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->text('message')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('partner_portal_settings')->insert(['id' => 1, 'is_enabled' => true,
            'message' => 'A partnerportál átmenetileg nem elérhető. Kérjük, próbálkozz később.',
            'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['View:PartnerPortalAvailability', 'Update:PartnerPortalAvailability'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_portal_settings');
        Permission::where('guard_name', 'web')->whereIn('name', [
            'View:PartnerPortalAvailability', 'Update:PartnerPortalAvailability',
        ])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
