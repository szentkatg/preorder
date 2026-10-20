<?php

namespace App\Filament\Support\Concerns;

trait HasPricingWorkflowNavigation
{
    public function queryStringHasPricingWorkflowNavigation(): array
    {
        return [
            'tableFilters' => ['except' => []],
            'pricingProjectId' => ['as' => 'workflowProject', 'except' => null],
            'pricingArchive' => ['as' => 'workflowArchive', 'except' => false],
        ];
    }

    public function mountHasPricingWorkflowNavigation(): void
    {
        $filters = request()->query('tableFilters');
        abort_unless($filters === null || is_array($filters), 422);
        if (is_array($filters) && array_key_exists('workflow_state', $filters)) {
            abort_unless(is_array($filters['workflow_state']), 422);
            $state = $filters['workflow_state']['value'] ?? '';
            abort_unless(in_array($state, ['', null, 'approved', 'pending'], true), 422);
            $filters['workflow_state'] = ['value' => $state ?: null];
        }

        // Restore the clicked project even when another tab changed the shared
        // preference. Existing context methods enforce open/archive boundaries.
        if (request()->query->has('workflowProject')) {
            $archive = request()->query('workflowArchive', '0');
            abort_unless(in_array($archive, ['0', '1', 'false', 'true'], true), 422);
            $archive = in_array($archive, ['1', 'true'], true);
            if ($this->pricingArchive !== $archive) {
                $this->setPricingArchive($archive);
            }
            $this->selectPricingProject(request()->query('workflowProject'));
        }
        // Run after page mount, where currency/outlet restoration may reset the
        // table. A dashboard click must not inherit unrelated old table filters.
        if ($filters !== null) {
            $this->tableFilters = $filters;
        }
    }
}
