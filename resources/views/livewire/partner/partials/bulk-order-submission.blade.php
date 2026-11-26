@if ($bulkSubmission !== [])
    <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" data-bulk-order-submission
        @if ($bulkSubmission['running']) wire:poll.1s.keep-alive="processBulkSubmission" @endif>
        <section role="dialog" aria-modal="true" aria-labelledby="bulk-submission-heading"
            class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
            <h2 id="bulk-submission-heading" class="text-lg font-semibold">{{ __('partner.submit_multiple_orders') }}</h2>
            @if (! $bulkSubmission['started'])
                <p class="mt-3">{{ __('partner.confirm_submit_multiple_orders', ['count' => $bulkSubmission['total']]) }}</p>
                <p class="mt-2 text-sm text-gray-600">{{ __('partner.submit_multiple_skip', ['count' => $bulkSubmission['already_submitted']]) }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="closeBulkSubmission" wire:loading.attr="disabled" class="rounded border px-4 py-2">{{ __('partner.erp_export_cancel') }}</button>
                    <button type="button" wire:click="startBulkSubmission" wire:loading.attr="disabled"
                        class="rounded bg-green-700 px-4 py-2 font-semibold text-white disabled:opacity-50">{{ __('partner.submit_order') }}</button>
                </div>
            @else
                <p class="mt-3" role="status" aria-live="polite">{{ __('partner.submit_multiple_progress', ['done' => $bulkSubmission['processed'], 'total' => $bulkSubmission['total']]) }}</p>
                <progress class="mt-2 w-full" value="{{ $bulkSubmission['processed'] }}" max="{{ $bulkSubmission['total'] }}"></progress>
                @if ($bulkSubmission['running'])
                    <p class="mt-2 text-sm text-gray-600">{{ __('partner.submit_multiple_keep_open') }}</p>
                @else
                    <p class="mt-3 font-semibold">{{ __('partner.submit_multiple_result', ['submitted' => $bulkSubmission['submitted'], 'skipped' => $bulkSubmission['skipped'], 'failed' => count($bulkSubmission['failed'])]) }}</p>
                @endif
                @if ($bulkSubmission['failed'] !== [])
                    <div role="alert" class="mt-3 rounded bg-red-100 p-3 text-red-900">
                        <strong>{{ __('partner.submit_multiple_failed') }}</strong>
                        <ul>@foreach ($bulkSubmission['failed'] as $reference)<li>{{ $reference }}</li>@endforeach</ul>
                    </div>
                @endif
                @if ($bulkSubmission['email_failed'] !== [])
                    <div role="alert" class="mt-3 rounded bg-amber-100 p-3 text-amber-900">
                        <p>{{ __('partner.submission_email_failed') }}</p>
                        <ul>@foreach ($bulkSubmission['email_failed'] as $reference)<li>{{ $reference }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="mt-4 flex justify-end">
                    <button type="button" wire:click="closeBulkSubmission" @disabled($bulkSubmission['running'])
                        class="rounded border px-4 py-2 disabled:opacity-50">{{ __('partner.close') }}</button>
                </div>
            @endif
        </section>
    </div>
@endif
