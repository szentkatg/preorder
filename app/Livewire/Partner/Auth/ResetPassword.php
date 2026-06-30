<?php

namespace App\Livewire\Partner\Auth;

use App\Models\PartnerUser;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Component;

class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = request('email', '');
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }

    public function resetPassword()
    {
        $this->validate();

        $status = Password::broker('partner_users')->reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (PartnerUser $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('partner.login')
                ->with('success', __('auth.password_reset'));
        }

        $this->addError('email', __($status));
    }

    public function render()
    {
        return view('livewire.partner.auth.reset-password');
    }
}