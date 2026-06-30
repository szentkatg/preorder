<?php

namespace App\Livewire\Partner\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Component;

class ForgotPassword extends Component
{
    public string $email = '';

    public ?string $status = null;

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function sendResetLink(): void
    {
        $this->validate();

        $status = Password::broker('partner_users')
            ->sendResetLink([
                'email' => $this->email,
            ]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = __($status);

            return;
        }

        $this->addError('email', __($status));
    }

    public function render()
    {
        return view('livewire.partner.auth.forgot-password');
    }
}