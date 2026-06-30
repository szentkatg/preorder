<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @if (! empty($statistics))
            <x-filament::section>
                <x-slot name="heading">
                    Statisztika
                </x-slot>

                <div class="space-y-1 text-sm">
                    @foreach ($statistics as $label => $value)
                        <div class="flex gap-2">
                            <div class="font-medium">{{ $label }}:</div>
                            <div>{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if (! empty($fatalValidationErrors))
            <x-filament::section>
                <x-slot name="heading">
                    Fatális hibák
                </x-slot>

                <ul class="list-disc pl-5 text-sm text-danger-600">
                    @foreach ($fatalValidationErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if (! empty($rowValidationErrors))
            <x-filament::section>
                <x-slot name="heading">
                    Sorhibák
                </x-slot>

                <ul class="list-disc pl-5 text-sm text-warning-600">
                    @foreach ($rowValidationErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>