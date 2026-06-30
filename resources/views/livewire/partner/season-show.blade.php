<div class="max-w-6xl mx-auto p-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold">
            {{ $season->name }}
        </h1>

        <div class="text-gray-600">
            Partner: {{ $partner->name }}
        </div>
    </div>

    <h2 class="text-xl font-semibold mb-4">
        Válassz címet
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        @foreach($addresses as $address)

            <div class="border rounded-lg p-4">

                <div class="font-semibold">
                    {{ $address->name }}
                </div>

                <div class="text-sm text-gray-500">
                    {{ $address->city }}
                </div>

                <div class="mt-4">
                    <a
                        href="{{ route('partner.addresses.show', [
                            'season' => $season,
                            'address' => $address,
                        ]) }}"
                        class="inline-block px-4 py-2 border rounded"
                    >
                        Kiválasztás
                    </a>
                </div>

            </div>

        @endforeach

    </div>

</div>