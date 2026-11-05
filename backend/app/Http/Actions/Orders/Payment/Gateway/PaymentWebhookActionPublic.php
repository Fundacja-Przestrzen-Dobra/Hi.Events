<?php

namespace HiEvents\Http\Actions\Orders\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Order;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Application\Handlers\Order\MarkOrderAsPaidHandler;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single webhook endpoint for every provider.
 *
 * Order of operations: verify authenticity -> store the notification in the
 * idempotent inbox -> apply the state change -> mark processed. A duplicate
 * delivery is stored once and never applied twice.
 */
class PaymentWebhookActionPublic extends BaseAction
{
    public function __construct(
        private readonly MarkOrderAsPaidHandler $markOrderAsPaidHandler,
    ) {
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $providerEnum = PaymentProviders::tryFrom(strtoupper($provider));

        abort_if($providerEnum === null, 404, 'Unknown payment provider');

        if ($providerEnum === PaymentProviders::OFFLINE) {
            abort(404, 'Offline payments have no webhook source');
        }

        $gateway = PaymentGatewayRegistry::fromConfig()->get($providerEnum);

        if (! $gateway->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $gateway->parseWebhook($request);

        $eventKey = $event->eventKey ?? $event->providerReference;

        $alreadyStored = DB::table('payment_webhook_events')
            ->where('provider', $providerEnum->value)
            ->where('event_key', $eventKey)
            ->exists();

        if ($alreadyStored) {
            return response()->json(['status' => 'duplicate']);
        }

        $inboxId = DB::table('payment_webhook_events')->insertGetId([
            'provider' => $providerEnum->value,
            'event_key' => $eventKey,
            'payload' => json_encode($event->rawPayload),
            'created_at' => now(),
        ]);

        $this->applyEvent($providerEnum, $event, $event->orderShortId);

        DB::table('payment_webhook_events')->where('id', $inboxId)->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    private function applyEvent(PaymentProviders $provider, $event, ?string $orderShortId): void
    {
        $paymentRow = DB::table('order_payments')
            ->where('provider', $provider->value)
            ->where('provider_reference', $event->providerReference);

        if ($orderShortId === null) {
            return;
        }

        $order = Order::query()->where('short_id', $orderShortId)->first();

        if ($order === null) {
            return;
        }

        $status = $event->status->value;

        $paymentRow->update([
            'status' => $status,
            'raw_payload' => json_encode($event->rawPayload),
            'updated_at' => now(),
        ]);

        if ($event->status !== PaymentEventStatus::PAID) {
            return;
        }

        // Reuse the same domain transition the organizer uses to confirm an
        // offline payment, so attendee statuses, invoices and e-mails stay
        // consistent across providers.
        try {
            $this->markOrderAsPaidHandler->handle(new MarkOrderAsPaidDTO(
                eventId: (int) $order->event_id,
                orderId: (int) $order->id,
            ));
        } catch (Throwable $exception) {
            // Already paid orders throw a conflict; that is a success for us.
            report($exception);
        }
    }
}
