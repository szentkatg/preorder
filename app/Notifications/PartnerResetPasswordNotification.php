<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartnerResetPasswordNotification extends Notification
{
    public function __construct(
        public string $token
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('partner.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject(__('partner.password_reset_email_subject'))
            ->view('emails.partner.reset-password', [
                'url' => $url,
                'user' => $notifiable,
            ]);
    }
}