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
        Schema::table('pricing_projects', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('pricing_project_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_project_id')->constrained()->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->json('snapshot');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->index(['pricing_project_id', 'id']);
        });
        foreach (['Publish:PricingProjectLifecycle', 'Reopen:PricingProjectLifecycle', 'Export:PricingProjectLifecycle'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_project_publications');
        Schema::table('pricing_projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
        // Permission assignments are intentionally retained on rollback.
    }
};
