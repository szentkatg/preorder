<?php

namespace App\Livewire\Partner\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('partner')->attempt([
            'email' => $this->email,
            'password' => $this->password,
            'active' => true,
        ])) {

            $this->addError(
                'email',
                'Hibás email cím vagy jelszó./Wrong email or password.'
            );

            return;
        }
        session()->forget([
            'partner_order_selector.season_id',
            'partner_order_selector.brand_id',
            'partner_order_selector.partner_address_id',
            'partner_order_selector.order_sheet_type_id',
            'partner_order_selector.order_id',
        ]);
        session()->regenerate();

        $this->redirectRoute(
            'partner.orders.select',
            navigate: true
        );
    }

    public function render()
    {
        return view('livewire.partner.auth.login');
    }
}