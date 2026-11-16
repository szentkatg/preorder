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
        Schema::create('sku_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->string('old_sku_code');
            $table->string('new_sku_code');
            $table->timestamps();
            $table->unique(['season_id', 'old_sku_code']);
        });

        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'] as $ability) {
            Permission::findOrCreate($ability.':SkuReplacement', 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_replacements');
        Permission::where('guard_name', 'web')->whereIn('name', array_map(
            fn ($ability) => $ability.':SkuReplacement', ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'],
        ))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
