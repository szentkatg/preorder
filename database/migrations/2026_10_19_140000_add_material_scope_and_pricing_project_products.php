<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertUnambiguousAssignments();

        // MySQL DDL is not transactional. Permit retry after an interrupted migration.
        if (! Schema::hasColumn('pricing_parameters', 'material_composition')) {
            Schema::table('pricing_parameters', fn (Blueprint $table) => $table->text('material_composition')->nullable());
        }
        if (! Schema::hasTable('pricing_project_products')) {
            Schema::create('pricing_project_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pricing_project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique('product_id');
            });
        }

        // Existing calculation rows define membership, not immutable historical publication snapshots.
        DB::transaction(function (): void {
            // Pricing writers also lock their project: recheck after acquiring every project lock.
            $projects = DB::table('pricing_projects')->orderBy('id')->lockForUpdate()->get();
            $this->assertUnambiguousAssignments();
            foreach ($projects as $project) {
                $ids = DB::table('pricing_calculation_rows')->where('pricing_project_id', $project->id)->distinct()->pluck('product_id');
                foreach ($ids->unique()->chunk(500) as $chunk) {
                    $existing = DB::table('products')->whereIn('id', $chunk)->pluck('id');
                    if ($existing->isNotEmpty()) {
                        if (DB::table('pricing_project_products')->whereIn('product_id', $existing)->where('pricing_project_id', '!=', $project->id)->exists()) {
                            throw new RuntimeException('Eltérő, már mentett termék-hozzárendelés. Kézi ellenőrzés szükséges; meglévő tagságot nem írunk felül.');
                        }
                        $missing = $existing->diff(DB::table('pricing_project_products')->whereIn('product_id', $existing)->pluck('product_id'));
                        if ($missing->isEmpty()) {
                            continue;
                        }
                        DB::table('pricing_project_products')->insert($missing->map(fn ($id) => [
                            'pricing_project_id' => $project->id, 'product_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                        ])->all());
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_project_products');
        Schema::table('pricing_parameters', fn (Blueprint $table) => $table->dropColumn('material_composition'));
    }

    private function assertUnambiguousAssignments(): void
    {
        $conflicts = DB::table('pricing_calculation_rows')->select('product_id')->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT pricing_project_id) > 1')->limit(20)->pluck('product_id');
        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException('Több árprojektben szereplő termékek vannak (termékazonosítók: '.$conflicts->implode(', ').
                '). Előbb kézzel rendezd a hozzárendeléseket. Részletek: php artisan pricing:check-product-assignments. Meglévő kalkulációt és árat nem módosítottunk.');
        }
    }
};
