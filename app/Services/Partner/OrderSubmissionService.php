<?php

namespace App\Services\Partner;

use App\Exports\PartnerOrderMatrixExport;
use App\Models\Order;
use App\Models\PartnerUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class OrderSubmissionService
{
    public function selectedOrders(array $ids): Collection
    {
        $user = $this->user();
        Validator::make(['selectedSummaryOrderIds' => $ids], [
            'selectedSummaryOrderIds' => ['required', 'array', 'min:1'],
            'selectedSummaryOrderIds.*' => ['required', 'integer', 'min:1'],
        ], ['selectedSummaryOrderIds.required' => __('partner.select_orders_to_submit')])->validate();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $orders = $user->accessibleOrdersQuery()->whereKey($ids)
            ->where('season_id', PartnerSeasonContext::id())->orderBy('id')->get();
        // Validate the whole selection before changing even one order.
        abort_unless($orders->count() === count($ids), 403);

        return $orders;
    }

    /** @return array{submitted: bool, email_failed: bool} */
    public function submit(int $orderId, bool $sendEmail = true): array
    {
        $user = $this->user();
        $order = DB::transaction(function () use ($user, $orderId): ?Order {
            $order = $user->accessibleOrdersQuery()->whereKey($orderId)
                ->where('season_id', PartnerSeasonContext::id())->lockForUpdate()->first();
            abort_unless($order instanceof Order, 403);
            if ($order->isSubmitted()) {
                return null;
            }
            $order->update(['status' => 'submitted', 'submitted_at' => now()]);

            return $order;
        });
        if (! $order) {
            return ['submitted' => false, 'email_failed' => false];
        }
        if (! $sendEmail) {
            return ['submitted' => true, 'email_failed' => false];
        }
        try {
            $this->sendConfirmation($order, $user);
        } catch (Throwable $error) {
            // Submission succeeded: never revert it or resend blindly after a transport failure.
            Log::error('Order submission confirmation failed', [
                'order_id' => $order->id, 'partner_user_id' => $user->id, 'exception_type' => $error::class,
            ]);

            return ['submitted' => true, 'email_failed' => true];
        }

        return ['submitted' => true, 'email_failed' => false];
    }

    private function user(): PartnerUser
    {
        $user = PartnerUser::find(auth('partner')->id());
        abort_unless($user?->active, 403);

        return $user;
    }

    private function sendConfirmation(Order $order, PartnerUser $user): void
    {
        if (! $user->email) {
            return;
        }
        $order->loadMissing(['partner', 'partnerAddress', 'brand', 'orderSheetType']);
        $export = new PartnerOrderMatrixExport($order);
        $filename = $export->filename();
        $excelContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        $subject = __('partner.order_submitted_email_subject').' | '.implode(' | ', array_filter([
            $order->partner?->erp_partner_code, $order->partner?->name, $order->partnerAddress?->name,
            $order->brand?->name, $order->orderSheetType?->translate('name'), $order->reference_number,
        ]));
        Mail::raw(__('partner.order_submitted_email_body'), function ($message) use ($user, $filename, $excelContent, $subject): void {
            $message->to($user->email)->subject($subject)
                ->attachData($excelContent, $filename, ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            if (filled($cc = config('app.order_notification_cc'))) {
                $message->cc($cc);
            }
        });
    }
}
