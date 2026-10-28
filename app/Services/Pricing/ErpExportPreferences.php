<?php

namespace App\Services\Pricing;

use App\Models\AdminUiPreference;
use App\Models\PricingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Export drafts, not pricing data. Never shared between users or projects. */
class ErpExportPreferences
{
    public function load(PricingProject $project): array
    {
        $this->authorize($project);
        $value = AdminUiPreference::where('user_id', auth()->id())
            ->where('preference_key', $this->key($project))->value('value');

        return is_array($value) ? $value : [];
    }

    public function remember(PricingProject $project, string $slot, string $field, mixed $state): void
    {
        $this->authorize($project);
        abort_unless(array_key_exists($slot, ErpPricingExportService::SLOTS)
            && in_array($field, ['mapping', 'formulas', 'step', 'mode', 'adjustment'], true), 422);
        // Incomplete formulas may be remembered as drafts, but never executed until
        // the export service validates grammar, references, rates and every result.
        if ($field === 'formulas') {
            if (! is_array($state) || ! array_is_list($state) || count($state) > ErpPriceFormula::MAX_TOKENS) {
                return;
            }
            foreach ($state as $token) {
                if (! is_array($token) || array_diff(array_keys($token), ['type', 'value']) !== []
                    || ! in_array($token['type'] ?? null, ['price_list', 'exchange_rate', 'number', '+', '-', '*', '/', '(', ')'], true)
                    || (isset($token['value']) && ((! is_string($token['value']) && ! is_int($token['value'])) || strlen((string) $token['value']) > 32))) {
                    return;
                }
            }
        } elseif ($state !== null && ((! is_string($state) && ! is_int($state) && ! is_float($state)) || strlen((string) $state) > 32)) {
            return;
        }
        DB::transaction(function () use ($project, $slot, $field, $state): void {
            $preference = AdminUiPreference::where('user_id', auth()->id())
                ->where('preference_key', $this->key($project))->lockForUpdate()->first()
                ?? new AdminUiPreference(['user_id' => auth()->id(), 'preference_key' => $this->key($project)]);
            $value = is_array($preference->value) ? $preference->value : [];
            if (in_array($field, ['mapping', 'formulas'], true)) {
                $value[$field][$slot] = $state;
            } else {
                $value['rounding'][$slot][$field] = $state;
            }
            $preference->value = $value;
            $preference->save();
        });
    }

    private function key(PricingProject $project): string
    {
        return 'erp_pricing_export_project_'.$project->id;
    }

    private function authorize(PricingProject $project): void
    {
        Gate::authorize('view', $project);
        Gate::authorize('exportErp', $project);
    }
}
