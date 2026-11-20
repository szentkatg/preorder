<?php

namespace App\Services\Auth;

use App\Models\LoginLog;
use App\Models\PartnerUser;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LoginAuditRecorder
{
    public function __construct(private Request $request) {}

    public function record(Login $event): void
    {
        if (! (($event->guard === 'web' && $event->user instanceof User)
            || ($event->guard === 'partner' && $event->user instanceof PartnerUser))) {
            return;
        }

        try {
            $ip = $this->request->ip();
            LoginLog::create([
                'guard' => $event->guard,
                'user_id' => $event->user->getAuthIdentifier(),
                'user_name' => Str::limit((string) $event->user->name, 255, ''),
                'user_email' => Str::limit((string) $event->user->email, 255, ''),
                // Never trust arbitrary forwarded headers or persist request data.
                'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'logged_in_at' => now()->utc(),
            ]);
        } catch (Throwable $exception) {
            // An audit storage outage must not break the existing login flow.
            // Do not log exception messages/queries, credentials, headers or tokens.
            try {
                Log::error('Login audit persistence failed.', [
                    'guard' => $event->guard,
                    'user_id' => $event->user->getAuthIdentifier(),
                    'exception_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Preserve authentication even if both logging destinations fail.
            }
        }
    }
}
