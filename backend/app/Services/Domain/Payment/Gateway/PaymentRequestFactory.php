<?php

namespace HiEvents\Services\Domain\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Collection;

/**
 * Builds a provider payment request from a persisted order.
 *
 * Kept out of the HTTP action so the mapping can be unit-tested without a database:
 * it is the place where domain-object accessors meet the provider's field names, and
 * a mistake here only shows up against a live provider.
 */
class PaymentRequestFactory
{
    public function fromOrder(
        OrderDomainObject $order,
        PaymentProviders $provider,
        int $eventId,
        ?string $buyerIp = null,
    ): CreatePaymentRequestDTO {
        $currency = (string) $order->getCurrency();
        $orderShortId = (string) $order->getShortId();

        return new CreatePaymentRequestDTO(
            orderId: (int) $order->getId(),
            orderShortId: $orderShortId,
            // The amount is taken from the stored order, never from the request.
            amount: MoneyValue::fromFloat((float) $order->getTotalGross(), $currency),
            currencyCode: $currency,
            description: sprintf('Order %s', $orderShortId),
            buyerEmail: $order->getEmail(),
            buyerFirstName: $order->getFirstName(),
            buyerLastName: $order->getLastName(),
            buyerLanguage: $order->getLocale() ?: 'pl',
            buyerIp: $buyerIp,
            returnUrl: sprintf(
                '%s/checkout/%d/%s/payment_return',
                rtrim((string) config('app.frontend_url'), '/'),
                $eventId,
                $orderShortId
            ),
            notifyUrl: (string) url(sprintf(
                '/api/public/webhooks/payments/%s',
                strtolower($provider->value)
            )),
            products: $this->products($order, $currency),
        );
    }

    /**
     * @return array<int, array{name: string, unitPrice: MoneyValue, quantity: int}>
     */
    private function products(OrderDomainObject $order, string $currency): array
    {
        $items = $order->getOrderItems();
        $items = $items instanceof Collection ? $items->all() : (array) $items;

        return array_map(
            static fn ($item): array => [
                'name' => (string) ($item->getItemName() ?? 'Ticket'),
                'unitPrice' => MoneyValue::fromFloat((float) $item->getPrice(), $currency),
                'quantity' => (int) $item->getQuantity(),
            ],
            $items
        );
    }
}
