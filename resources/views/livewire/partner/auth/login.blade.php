<div class="max-w-md mx-auto mt-10">
    <form wire:submit="login" class="space-y-4">
        <h1 class="text-2xl font-bold">Partner bejelentkezés/Partner login</h1>

        <div>
            <label>Email</label>
            <input type="email" wire:model="email" class="w-full border rounded p-2">

            @error('email')
                <div class="text-red-600 text-sm">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label>Jelszó/Password</label>
            <input type="password" wire:model="password" class="w-full border rounded p-2">
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-slate-900 px-4 py-2.5 font-semibold text-white shadow hover:bg-slate-800"
        >
            Belépés/Login
        </button>
        
        <div class="text-center">
            <a
                href="{{ route('partner.password.request') }}"
                class="text-sm text-blue-600 hover:underline"
            >
                Elfelejtett jelszó?
            </a>
        </div>
    </form>
</div>