<x-filament-panels::page>

    <div class="rounded-lg border bg-white p-6">
        {{ $this->form }}
    </div>

    <div class="mt-4">
        <x-filament::button wire:click="validateImport">
            Validálás
        </x-filament::button>
    </div>

    @if(count($validationErrors))
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4">
            <h3 class="font-bold text-red-700">
                Hibák
            </h3>

            <ul class="mt-2 list-disc pl-5">
                @foreach($validationErrors as $error)
                    <li>
                        @if(is_array($error))
                            <pre>{{ json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @else
                            {{ $error }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(count($statistics))
        <div class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4">
            <h3 class="font-bold">
                Eredmény
            </h3>

            <pre class="mt-2 overflow-auto text-sm">
{{ json_encode($statistics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
            </pre>
        </div>
    @endif

</x-filament-panels::page>