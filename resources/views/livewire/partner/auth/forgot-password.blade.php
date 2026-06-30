<div class="max-w-md mx-auto mt-10">
    <form wire:submit="sendResetLink" class="space-y-4">
        <h1 class="text-2xl font-bold">
            {{ __('partner.forgot_password_title') }}
        </h1>

        <p class="text-sm text-gray-600">
            {{ __('partner.forgot_password_text') }}
        </p>

        @if($status)
            <div class="rounded bg-green-100 p-3 text-green-800">
                {{ $status }}
            </div>
        @endif

        <div>
            <label>{{ __('partner.email') }}</label>

            <input
                type="email"
                wire:model="email"
                class="w-full border rounded p-2"
            >

            @error('email')
                <div class="text-red-600 text-sm">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-semibold text-white shadow hover:bg-slate-800"
        >
            {{ __('partner.send_reset_link') }}
        </button>

        <div class="text-center">
            <a
                href="{{ route('partner.login') }}"
                class="text-blue-600 hover:underline"
            >
                {{ __('partner.back_to_login') }}
            </a>
        </div>
    </form>
</div>