<div class="max-w-6xl mx-auto p-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold">
            Előrendelési Portál
        </h1>

        <div class="mt-2 text-gray-600">
            Üdv, {{ $user->name }}
        </div>

        <div class="text-gray-600">
            Partner: {{ $user->partner->name }}
        </div>
    </div>

    <h2 class="text-xl font-semibold mb-4">
        Aktív szezonok
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        @forelse($seasons as $season)

            <div class="border rounded-lg p-4 shadow-sm">

                <div class="text-lg font-semibold">
                    {{ $season->name }}
                </div>

                <div class="text-sm text-gray-500 mt-2">
                    Határidő:
                    {{ optional($season->deadline)->format('Y-m-d') }}
                </div>

                <div class="mt-4">
                    <a
                        href="{{ route('partner.seasons.show', $season) }}"
                        class="inline-block px-4 py-2 border rounded"
                    >
                        Megnyitás
                    </a>
                </div>

            </div>

        @empty

            <div>
                Nincs aktív szezon.
            </div>

        @endforelse

    </div>

</div>