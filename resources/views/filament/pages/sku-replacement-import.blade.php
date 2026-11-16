<x-filament-panels::page>
    {{ $this->form }}
    @if ($preview)
        <x-filament::section heading="Import előnézete">
            <p class="text-sm">Összesen: {{ $preview['total'] }} sor.
                Új: {{ $preview['counts']['create'] }}, felülírandó: {{ $preview['counts']['overwrite'] }},
                kihagyott: {{ $preview['counts']['skip'] }}, változatlan: {{ $preview['counts']['unchanged'] }}.
            </p>
            @if ($preview['error_count'])
                <p role="alert" class="mt-2 text-sm text-danger-600">{{ $preview['error_count'] }} hiba miatt az import nem indítható. Semmit sem mentettünk.</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @foreach ($preview['errors'] as $error) <li>{{ $error }}</li> @endforeach
                </ul>
                @if ($preview['error_count'] > 100) <p class="text-sm">Az első 100 hibát mutatjuk.</p> @endif
            @else
                <p class="mt-2 text-sm">Az ellenőrzés sikeres. Az Importálás gombbal és a megerősítéssel mentheted a cikkcseréket.</p>
            @endif
            <div class="mt-4 overflow-auto">
                <table class="w-full text-sm">
                    <thead><tr><th class="p-2 text-left">Excel-sor</th><th class="p-2 text-left">Szezon</th><th class="p-2 text-left">Régi SKU</th><th class="p-2 text-left">Új SKU / TÖRÖLT</th><th class="p-2 text-left">Művelet</th></tr></thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr class="border-t">
                                <td class="p-2">{{ $row['row_number'] }}</td><td class="p-2">{{ $row['season_code'] }}</td>
                                <td class="p-2">{{ $row['old_sku_code'] }} @if ($row['old_sku_name']) – {{ $row['old_sku_name'] }} @endif</td>
                                <td class="p-2">{{ $row['new_sku_code'] }} @if ($row['new_sku_name']) – {{ $row['new_sku_name'] }} @endif</td>
                                <td class="p-2">{{ ['create' => 'Új', 'overwrite' => 'Felülírás', 'skip' => 'Kihagyás', 'unchanged' => 'Változatlan', 'invalid' => 'Hibás'][$row['status']] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($preview['total'] > 100) <p class="mt-2 text-sm">Az első 100 sort mutatjuk; az ellenőrzés és az import a teljes fájlra vonatkozik.</p> @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
