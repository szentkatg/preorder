<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckPricingProductAssignments extends Command
{
    protected $signature = 'pricing:check-product-assignments';

    protected $description = 'Csak olvasható ellenőrzés: több árprojektben szereplő termékek listázása migráció előtt.';

    public function handle(): int
    {
        $ids = DB::table('pricing_calculation_rows')->select('product_id')->groupBy('product_id')
            ->havingRaw('COUNT(DISTINCT pricing_project_id) > 1')->pluck('product_id');
        if ($ids->isEmpty()) {
            $this->info('Nincs ütköző termék-hozzárendelés. A migráció futtatható.');

            return self::SUCCESS;
        }
        $this->error($ids->count().' termék több árprojektben szerepel. A migráció előtt kézzel rendezendő.');
        $rows = [];
        foreach ($ids as $id) {
            $product = DB::table('products')->find($id);
            $projects = DB::table('pricing_projects')->whereIn('id', DB::table('pricing_calculation_rows')
                ->where('product_id', $id)->select('pricing_project_id')->distinct())->orderBy('id')->get();
            foreach ($projects as $project) {
                $rows[] = [$id, $product?->model_code ?? '?', $project->id, $project->name, $project->status];
            }
        }
        $this->table(['Termék ID', 'Modellkód', 'Árprojekt ID', 'Árprojekt', 'Státusz'], $rows);
        $this->warn('Nincs automatikus felülírás vagy törlés. Válaszd ki a megfelelő projektet, a többi projektből annak felületén töröld a modell kalkulációit. A publikált árakat és snapshotokat ne módosítsd.');

        return self::FAILURE;
    }
}
