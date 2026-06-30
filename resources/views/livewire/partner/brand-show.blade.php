<div class="max-w-6xl mx-auto p-6">

    <div class="mb-8">

        <h1 class="text-3xl font-bold">
            {{ $season->name }}
        </h1>

        <div class="text-gray-600">
            {{ $address->name }}
        </div>

        <div class="text-gray-600">
            Márka: {{ $brand->name }}
        </div>

    </div>

    <h2 class="text-xl font-semibold mb-4">
        Válassz rendelőlap típust
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        @foreach($orderSheetTypes as $type)

            <div class="border rounded-lg p-4">

                <div class="text-lg font-semibold">
                    {{ $type->name_hu }}
                </div>

                <div class="mt-4">
                    <a
                        href="{{ route('partner.products.index', [
                            'season' => $season,
                            'address' => $address,
                            'brand' => $brand,
                            'type' => $type,
                        ]) }}"
                        class="inline-block px-4 py-2 border rounded"
                    >
                        Megnyitás
                    </a>
                </div>

            </div>

        @endforeach

    </div>

</div>