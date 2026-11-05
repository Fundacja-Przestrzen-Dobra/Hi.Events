<?php

namespace HiEvents\Http\Actions\Orders\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Order;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Application\Handlers\Order\MarkOrderAsPaidHandler;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lets the checkout poll for the outcome of an asynchronous (redirect) payment.
 *
 * With `?verify=1` the endpoint additionally asks the provider for the current status
 * of every outstanding payment. This is the safety net for a webhook that never
 * arrived (unreachable notify URL, provider outage, delayed delivery): the buyer has
 * just come back from the provider's page, so the outcome is knowable. The state
 * change goes through the same mark-as-paid transition as the webhook and the
 * organizer's manual confirmation, so a payment already applied is never applied
 * twice.
 */
class GetPaymentStatusActionPublic extends BaseAction
{
    public function __construct(
        private readonly MarkOrderAsPaidHandler $markOrderAsPaidHandler,
    ) {
    }

    public function __invoke(Request $request, int $eventId, string $orderShortId): JsonResponse
    {
        $order = Order::query()
            ->where('short_id', $orderShortId)
            ->where('event_id', $eventId)
            ->first();

        abort_if($order === null, 404, 'Order not found');

        $verified = $request->boolean('verify') ? $this->reconcile($order) : false;

        $order->refresh();

        $payments = DB::table('order_payments')
            ->where('order_id', $order->id)
            ->orderByDesc('updated_at')
            ->get(['provider', 'provider_reference', 'status', 'amount', 'currency', 'updated_at']);

        return response()->json([
            'data' => [
                'order_short_id' => $order->short_id,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
                'verified' => $verified,
                'payments' => $payments,
            ],
        ]);
    }

    /**
     * @return bool whether the provider confirmed a payment for this order
     */
    private function reconcile(Order $order): bool
    {
        if ($order->payment_status === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
            return false;
        }

        $outstanding = DB::table('order_payments')
            ->where('order_id', $order->id)
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->orderByDesc('updated_at')
            ->get(['id', 'provider', 'provider_reference']);

        if ($outstanding->isEmpty()) {
            return false;
        }

        $registry = PaymentGatewayRegistry::fromConfig();

        foreach ($outstanding as $payment) {
            $provider = PaymentProviders::tryFrom(strtoupper((string) $payment->provider));

            if ($provider === null || $provider === PaymentProviders::OFFLINE) {
                continue;
            }

            try {
                $gateway = $registry->get($provider);
            } catch (Throwable $exception) {
                report($exception);
                continue;
            }

            if (! $gateway->isConfigured()) {
                continue;
            }

            try {
                $status = $gateway->fetchStatus((string) $payment->provider_reference);
            } catch (Throwable $exception) {
                // A provider we cannot reach must not turn the checkout poll into an
                // error: a webhook or a later poll will settle the payment.
                report($exception);
                continue;
            }

            DB::table('order_payments')->where('id', $payment->id)->update([
                'status' => $status->status->value,
                'raw_payload' => json_encode($status->rawResponse),
                'updated_at' => now(),
            ]);

            if (! $status->isPaid()) {
                continue;
            }

            try {
                $this->markOrderAsPaidHandler->handle(new MarkOrderAsPaidDTO(
                    eventId: (int) $order->event_id,
                    orderId: (int) $order->id,
                    allowReservedOrder: true,
                ));
            } catch (Throwable $exception) {
                // An order that is already paid raises a conflict — a success here.
                report($exception);
            }

            return true;
        }

        return false;
    }
}
