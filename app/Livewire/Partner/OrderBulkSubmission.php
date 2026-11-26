<?php

namespace App\Livewire\Partner;

use App\Livewire\Partner\Concerns\RequiresSelectedPartnerSeason;
use App\Services\Partner\OrderSubmissionService;
use App\Services\Partner\PartnerSeasonContext;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class OrderBulkSubmission extends Component
{
    use RequiresSelectedPartnerSeason;

    #[Locked]
    public ?int $seasonId = null;

    public array $selectedSummaryOrderIds = [];

    /** Server-owned snapshot, separate from the table's live selection and filters. */
    #[Locked]
    public array $bulkSubmission = [];

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function mount(): void
    {
        $this->seasonId = PartnerSeasonContext::id();
    }

    #[On('open-order-bulk-submission')]
    public function prepareBulkSubmission(array $orderIds): void
    {
        abort_if($this->bulkSubmission['running'] ?? false, 409);
        $this->selectedSummaryOrderIds = $orderIds;
        $this->openBulkSubmission();
    }

    public function openBulkSubmission(): void
    {
        abort_if($this->bulkSubmission['running'] ?? false, 409);
        $this->resetValidation('selectedSummaryOrderIds');
        $orders = app(OrderSubmissionService::class)->selectedOrders($this->selectedSummaryOrderIds);
        $this->bulkSubmission = [
            'orders' => $orders->map(fn ($order) => ['id' => $order->id, 'reference' => $order->reference_number])->all(),
            'total' => $orders->count(), 'already_submitted' => $orders->filter->isSubmitted()->count(),
            'started' => false, 'running' => false, 'processed' => 0, 'position' => 0, 'submitted' => 0, 'skipped' => 0,
            'failed' => [], 'email_failed' => [],
        ];
    }

    public function startBulkSubmission(): void
    {
        abort_unless($this->bulkSubmission !== [] && ! $this->bulkSubmission['started'], 409);
        $orders = app(OrderSubmissionService::class)->selectedOrders(array_column($this->bulkSubmission['orders'], 'id'));
        // Skip already submitted orders in one query, not one browser request per order.
        $this->bulkSubmission['skipped'] = $orders->filter->isSubmitted()->count();
        $this->bulkSubmission['processed'] = $this->bulkSubmission['skipped'];
        $this->bulkSubmission['orders'] = $orders->reject->isSubmitted()->values()
            ->map(fn ($order) => ['id' => $order->id, 'reference' => $order->reference_number])->all();
        $this->bulkSubmission['started'] = $this->bulkSubmission['running'] = true;
        if ($this->bulkSubmission['processed'] === $this->bulkSubmission['total']) {
            $this->finishBulkSubmission();

            return;
        }
        $this->processBulkSubmission();
    }

    public function processBulkSubmission(): void
    {
        if (! ($this->bulkSubmission['running'] ?? false)) {
            return;
        }
        $index = $this->bulkSubmission['position'];
        $entry = $this->bulkSubmission['orders'][$index];
        try {
            $result = app(OrderSubmissionService::class)->submit($entry['id']);
            $this->bulkSubmission[$result['submitted'] ? 'submitted' : 'skipped']++;
            if ($result['email_failed']) {
                $this->bulkSubmission['email_failed'][] = $entry['reference'];
            }
        } catch (Throwable $error) {
            report($error);
            $this->bulkSubmission['failed'][] = $entry['reference'];
        }
        $this->bulkSubmission['processed']++;
        $this->bulkSubmission['position']++;
        if ($this->bulkSubmission['processed'] === $this->bulkSubmission['total']) {
            $this->finishBulkSubmission();
        }
    }

    private function finishBulkSubmission(): void
    {
        $this->bulkSubmission['running'] = false;
        $this->selectedSummaryOrderIds = [];
        $this->dispatch('orders-bulk-submitted');
    }

    public function closeBulkSubmission(): void
    {
        abort_if($this->bulkSubmission['running'] ?? false, 409);
        $this->bulkSubmission = [];
    }

    public function render()
    {
        return view('livewire.partner.order-bulk-submission');
    }
}
