<?php

namespace App\Services\Partner;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\PartnerUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OrderStatusAuditRecorder
{
    public function __construct(private Request $request) {}

    /** Called inside the same database transaction as the actual status change. */
    public function transition(Order $order, PartnerUser $user, string $fromStatus, string $event, bool $sendEmail, string $source): OrderStatusLog
    {
        $order->loadMissing(['partner', 'partnerAddress', 'season']);
        $ip = $this->request->ip();

        return OrderStatusLog::create([
            'order_id' => $order->id, 'reference_number' => Str::limit((string) $order->reference_number, 255, ''),
            'partner_code' => $order->partner?->erp_partner_code, 'partner_name' => $order->partner?->name,
            'address_code' => $order->partnerAddress?->addrid, 'season_code' => $order->season?->code,
            'partner_user_id' => $user->id, 'user_name' => Str::limit((string) $user->name, 255, ''),
            'user_email' => Str::limit((string) $user->email, 255, ''),
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'event' => $event, 'source' => $source, 'from_status' => $fromStatus, 'to_status' => $order->status,
            'occurred_at' => now()->utc(), 'email_requested' => $sendEmail,
            'email_status' => $event === 'reopened' ? 'not_applicable' : ($sendEmail ? 'pending' : 'not_requested'),
        ]);
    }

    public function emailResult(OrderStatusLog $entry, string $status, ?string $errorType = null): void
    {
        try {
            $entry->update(['email_status' => $status, 'email_finished_at' => now()->utc(), 'email_error_type' => $errorType]);
        } catch (Throwable $error) {
            // Never label a successfully sent email as failed because its audit UPDATE failed.
            // The committed transition remains, with a pending/unknown email outcome.
            try {
                Log::error('Order email audit result persistence failed', [
                    'audit_id' => $entry->id, 'order_id' => $entry->order_id, 'exception_type' => $error::class,
                ]);
            } catch (Throwable) {
            }
        }
    }
}
