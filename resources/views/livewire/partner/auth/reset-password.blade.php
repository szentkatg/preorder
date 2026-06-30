<div class="max-w-md mx-auto mt-10">
    <form wire:submit="resetPassword" class="space-y-4">
        <h1 class="text-2xl font-bold">
            {{ __('partner.reset_password_title') }}
        </h1>

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

        <div>
            <label>{{ __('partner.new_password') }}</label>

            <input
                type="password"
                wire:model="password"
                class="w-full border rounded p-2"
            >

            @error('password')
                <div class="text-red-600 text-sm">
                    {{ $message }}
                </div>
            @enderror
        </div>

        <div>
            <label>{{ __('partner.confirm_new_password') }}</label>

            <input
                type="password"
                wire:model="password_confirmation"
                class="w-full border rounded p-2"
            >
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-semibold text-white shadow hover:bg-slate-800"
        >
            {{ __('partner.save_password') }}
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