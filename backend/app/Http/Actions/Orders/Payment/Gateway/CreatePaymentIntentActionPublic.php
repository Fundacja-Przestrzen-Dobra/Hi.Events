<?php

namespace HiEvents\Http\Actions\Orders\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\DTO\GetOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\GetOrderPublicHandler;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayRegistry;
use HiEvents\Values\MoneyValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Starts a payment for an existing order with any registered provider.
 * The amount always comes from the persisted order, never from the request.
 */
class CreatePaymentIntentActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetOrderPublicHandler $getOrderPublicHandler,
    ) {
    }

    public function __invoke(Request $request, int $eventId, string $orderShortId, string $provider): JsonResponse
    {
        $providerEnum = PaymentProviders::tryFrom(strtoupper($provider));

        abort_if($providerEnum === null, 404, 'Unknown payment provider');

        if ($providerEnum === PaymentProviders::OFFLINE) {
            abort(422, 'Offline payments do not need a payment intent');
        }

        $gateway = PaymentGatewayRegistry::fromConfig()->get($providerEnum);

        abort_unless($gateway->isConfigured(), 422, 'This payment provider is not configured');

        $order = $this->getOrderPublicHandler->handle(new GetOrderPublicDTO(
            eventId: $eventId,
            orderShortId: $orderShortId,
            includeEventInResponse: false,
        ));

        abort_unless($gateway->supportsCurrency($order->getCurrency()), 422, 'Unsupported currency for this provider');

        $currency = $order->getCurrency();

        $paymentRequest = new CreatePaymentRequestDTO(
            orderId: $order->getId(),
            orderShortId: $order->getShortId(),
            amount: MoneyValue::fromFloat((float) $order->getTotalGross(), $currency),
            currencyCode: $currency,
            description: sprintf('Order %s', $order->getShortId()),
            buyerEmail: $order->getEmail(),
            buyerFirstName: $order->getFirstName(),
            buyerLastName: $order->getLastName(),
            buyerLanguage: $order->getLocale() ?: 'pl',
            buyerIp: $request->ip(),
            returnUrl: sprintf('%s/checkout/%d/%s/payment_return', config('app.frontend_url'), $eventId, $orderShortId),
            notifyUrl: url(sprintf('/api/public/webhooks/payments/%s', strtolower($providerEnum->value))),
            products: array_map(
                static fn ($item): array => [
                    'name' => (string) ($item->getName() ?? 'Ticket'),
                    'unitPrice' => MoneyValue::fromFloat((float) $item->getPrice(), $currency),
                    'quantity' => (int) $item->getQuantity(),
                ],
                $order->getOrderItems() ?? []
            ),
        );

        $payment = $gateway->createPayment($paymentRequest);

        DB::table('order_payments')->updateOrInsert(
            [
                'provider' => $providerEnum->value,
                'provider_reference' => $payment->providerReference,
            ],
            [
                'order_id' => $order->getId(),
                'status' => 'PENDING',
                'amount' => $order->getTotalGross(),
                'currency' => $currency,
                'raw_payload' => json_encode($payment->rawResponse),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'data' => [
                'provider' => $providerEnum->value,
                'reference' => $payment->providerReference,
                'redirect_url' => $payment->redirectUrl,
                'client_secret' => $payment->clientSecret,
            ],
        ]);
    }
}
