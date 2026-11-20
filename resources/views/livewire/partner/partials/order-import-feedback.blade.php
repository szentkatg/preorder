<div data-order-import-feedback x-data
    x-on:order-import-feedback.window="
        if ($event.detail.componentId === $wire.$id) {
            $nextTick(() => requestAnimationFrame(() => $el.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'end'
            })))
        }
    ">
    @if (! empty($importPreviews))
        <div
            wire:key="import-previews-{{ $this->getId() }}"
            class="mt-4 rounded-lg border border-orange-300 bg-orange-50 p-4 text-orange-950"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="font-semibold">{{ __('partner.import_preview') }}</div>
                    <div class="text-sm">{{ $importPreviews['message'] ?? '' }}</div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="selectAllImportPreviews"
                        class="inline-flex items-center rounded-lg border border-orange-300 bg-white px-3 py-2 text-xs font-semibold text-orange-900 hover:bg-orange-100"
                    >
                        {{ __('partner.select_all') }}
                    </button>
                    <button
                        type="button"
                        wire:click="clearSelectedImportPreviews"
                        class="inline-flex items-center rounded-lg border border-orange-300 bg-white px-3 py-2 text-xs font-semibold text-orange-900 hover:bg-orange-100"
                    >
                        {{ __('partner.clear_selection') }}
                    </button>
                    <button
                        type="button"
                        wire:click="importSelectedExcelFiles"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center rounded-lg bg-orange-600 px-4 py-2 text-xs font-semibold text-white hover:bg-orange-700 disabled:opacity-50"
                    >
                        {{ __('partner.import_selected') }}
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-orange-200 bg-white">
                <table class="min-w-full divide-y divide-orange-200 text-sm">
                    <thead class="bg-orange-100 text-left text-xs font-semibold uppercase tracking-wide text-orange-950">
                        <tr>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2">{{ __('partner.file') }}</th>
                            <th class="px-3 py-2">{{ __('partner.reference_number') }}</th>
                            <th class="px-3 py-2">{{ __('partner.address_code') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('partner.existing_order_quantity') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('partner.existing_order_value') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('partner.changed_rows') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('partner.new_order_quantity') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('partner.new_order_value') }}</th>
                            <th class="px-3 py-2">{{ __('partner.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-orange-100">
                        @foreach (($importPreviews['files'] ?? []) as $filePreview)
                            <tr wire:key="import-preview-{{ $filePreview['key'] }}" class="{{ ($filePreview['success'] ?? false) ? '' : 'bg-red-50 text-red-800' }}">
                                <td class="px-3 py-2">
                                    @if ($filePreview['success'] ?? false)
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedImportPreviewKeys"
                                            value="{{ $filePreview['key'] }}"
                                            class="rounded border-orange-300 text-orange-600 focus:ring-orange-500"
                                        />
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-medium">{{ $filePreview['filename'] ?? '' }}</td>
                                <td class="px-3 py-2">
                                    @if ($filePreview['missing_reference_number'] ?? false)
                                        <input type="text" maxlength="100"
                                            wire:model.blur="importReferenceNumbers.{{ $filePreview['key'] }}"
                                            aria-label="{{ __('partner.import_reference_number_label') }} — {{ $filePreview['filename'] }}"
                                            placeholder="{{ __('partner.reference_number') }}"
                                            class="w-48 rounded border border-orange-400 bg-white px-2 py-1 text-gray-900 focus:ring-orange-500" />
                                        <div class="mt-1 text-xs">{{ __('partner.import_reference_number_hint') }}</div>
                                        @error('importReferenceNumbers.'.$filePreview['key'])
                                            <div class="text-xs text-red-700">{{ $message }}</div>
                                        @enderror
                                    @else
                                        {{ $filePreview['reference_number'] ?? '' }}
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $filePreview['address_code'] ?? '' }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((int) ($filePreview['stats']['existing_quantity'] ?? 0), 0, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((float) ($filePreview['stats']['existing_value'] ?? 0), 0, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((int) ($filePreview['stats']['changed'] ?? 0), 0, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((int) ($filePreview['stats']['new_quantity'] ?? 0), 0, ',', ' ') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((float) ($filePreview['stats']['new_value'] ?? 0), 0, ',', ' ') }}</td>
                                <td class="px-3 py-2">{{ $filePreview['message'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

        @if (! empty($importResults))
            <div
                wire:key="import-results-{{ md5(($importResults['message'] ?? '') . count($importResults['files'] ?? [])) }}"
                x-data="{ open: true }"
                x-show="open"
                id="import-results-panel"
                class="mt-4 rounded-lg border p-4
            {{ ($importResults['success'] ?? false)
                ? 'border-green-300 bg-green-50 text-green-800'
                : 'border-red-300 bg-red-50 text-red-800' }}"
        >
            <div class="mb-3 flex items-center justify-between">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="text-lg font-bold text-gray-500 hover:text-gray-700"
                    title="Bezárás"
                >
                    ×
                </button>
            </div>
            <div class="font-semibold">
                {{ $importResults['message'] ?? '' }}
            </div>

            @if (! empty($importResults['files']))
                <div class="mt-4 space-y-3">
                    @foreach ($importResults['files'] as $fileResult)
                        <div class="rounded border bg-white p-3 text-sm text-gray-800">
                            <div class="font-semibold">
                                {{ $fileResult['filename'] ?? '' }}
                            </div>

                            @if (! empty($fileResult['reference_number']))
                                <div>
                                    <strong>{{ __('partner.reference_number') }}:</strong>
                                    {{ $fileResult['reference_number'] }}
                                </div>
                            @endif

                            @if (! empty($fileResult['address_code']))
                                <div>
                                    <strong>Címkód:</strong>
                                    {{ $fileResult['address_code'] }}
                                </div>
                            @endif

                            <div>
                                {{ $fileResult['message'] ?? '' }}
                            </div>

                            @if (! empty($fileResult['stats']))
                                <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                                    <div><strong>Módosítva:</strong> {{ $fileResult['stats']['updated'] ?? 0 }}</div>
                                    <div><strong>Törölve:</strong> {{ $fileResult['stats']['deleted'] ?? 0 }}</div>
                                    <div><strong>Változatlan:</strong> {{ $fileResult['stats']['unchanged'] ?? 0 }}</div>
                                    <div><strong>Hibás:</strong> {{ $fileResult['stats']['invalid'] ?? 0 }}</div>
                                    <div><strong>Összes változás:</strong> {{ $fileResult['stats']['changed'] ?? 0 }}</div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

@php
    $importErrorRows = [];

    foreach (($importResults['files'] ?? []) as $fileResult) {
        foreach (($fileResult['stats']['warnings'] ?? []) as $warning) {
            $row = is_array($warning) ? $warning : ['message' => $warning];

            $row['_filename'] = $fileResult['filename'] ?? '';
            $row['_address_code'] = $fileResult['address_code'] ?? '';

            $importErrorRows[] = $row;
        }
    }
@endphp

    @if (! empty($importErrorRows))
        <div id="import-errors" class="mt-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4">
            <div class="mb-3 font-semibold text-yellow-900">
                Hibás / kihagyott tételek
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-yellow-200 text-sm">
                    <thead>
                        <tr class="text-left text-yellow-900">
                            <th class="px-3 py-2">Fájl</th>
                            <th class="px-3 py-2">Címkód</th>
                            <th class="px-3 py-2">Sor</th>
                            <th class="px-3 py-2">SKU</th>
                            <th class="px-3 py-2">Méret</th>
                            <th class="px-3 py-2">Mennyiség</th>
                            <th class="px-3 py-2">Hiba</th>
                            <th class="px-3 py-2">JSON</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-yellow-100 bg-white">
                        @foreach ($importErrorRows as $errorRow)
                            <tr>
                                <td class="px-3 py-2">
                                    {{ $errorRow['_filename'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['_address_code'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['row'] ?? $errorRow['row_number'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['sku'] ?? $errorRow['SKU'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['size'] ?? $errorRow['size_name'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['quantity'] ?? $errorRow['qty'] ?? '' }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $errorRow['message'] ?? $errorRow['error'] ?? '' }}
                                </td>

                                <td class="px-3 py-2 text-xs">
                                    <code>
                                        {{ json_encode($errorRow, JSON_UNESCAPED_UNICODE) }}
                                    </code>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
