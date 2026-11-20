<label
    for="order-import-files-{{ $this->getId() }}"
    class="inline-flex cursor-pointer items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600"
>
    {{ __('partner.select_import_files') }}
</label>

<input
    id="order-import-files-{{ $this->getId() }}"
    type="file"
    wire:model="excelFiles"
    multiple
    accept=".xlsx,.xls"
    class="hidden"
/>

@if (! empty($excelFiles))
    <div class="flex flex-wrap items-start gap-3">
        <div class="text-sm text-gray-700">
            @foreach ($excelFiles as $file)
                <div>{{ $file->getClientOriginalName() }}</div>
            @endforeach
        </div>

        <button
            type="button"
            wire:click="previewExcelImports"
            wire:loading.attr="disabled"
            class="inline-flex items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-50"
        >
            {{ __('partner.preview_import') }}
        </button>
    </div>
@endif

@error('excelFiles') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
@error('excelFiles.*') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
<div wire:loading wire:target="excelFiles,previewExcelImports,importSelectedExcelFiles,importReferenceNumbers" role="status" class="text-sm text-gray-600">{{ __('partner.loading') }}</div>
