<div class="max-w-7xl mx-auto p-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold">
            {{ $season->name }} / {{ $brand->name }} / {{ $type->name_hu }}
        </h1>

        <div class="text-gray-600">
            Cím: {{ $address->name }}
        </div>
    </div>

    <h2 class="text-xl font-semibold mb-4">
        Katalóguscsoportok
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">

        @forelse($catalogGroups as $catalogGroup)

            <a
                href="{{ route('partner.catalog-group-order', [
                    'order' => $order->id,
                    'catalogGroupName' => $catalogGroup->catalog_group_name_hu,
                ]) }}"
                class="block border rounded-lg p-4 hover:bg-gray-50"
            >
                <div class="font-semibold text-lg">
                    {{ $catalogGroup->catalog_group_name_hu }}
                </div>

                <div class="mt-4">
                    <span class="inline-block rounded bg-black px-4 py-2 text-white">
                        Rendelés
                    </span>
                </div>
            </a>

        @empty

            <div>
                Nincs megjeleníthető katalóguscsoport.
            </div>

        @endforelse

    </div>

</div>