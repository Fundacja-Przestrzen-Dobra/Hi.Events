<?php

namespace HiEvents\Http\Actions\Orders\Payment\Gateway;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Lets the checkout poll for the outcome of an asynchronous (redirect) payment.
 */
class GetPaymentStatusActionPublic extends BaseAction
{
    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        $order = Order::query()
            ->where('short_id', $orderShortId)
            ->where('event_id', $eventId)
            ->first();

        abort_if($order === null, 404, 'Order not found');

        $payments = DB::table('order_payments')
            ->where('order_id', $order->id)
            ->orderByDesc('updated_at')
            ->get(['provider', 'provider_reference', 'status', 'amount', 'currency', 'updated_at']);

        return response()->json([
            'data' => [
                'order_short_id' => $order->short_id,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
                'payments' => $payments,
            ],
        ]);
    }
}
