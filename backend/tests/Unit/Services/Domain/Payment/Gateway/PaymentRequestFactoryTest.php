<?php

namespace Tests\Unit\Services\Domain\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Services\Domain\Payment\Gateway\PaymentRequestFactory;
use Tests\TestCase;

/**
 * The mapping from a stored order to a provider request. A live run against the PayU
 * sandbox failed here first (a collection passed to array_map, a wrong accessor for the
 * item name), so this covers the mapping that unit tests of the gateways cannot see.
 */
class PaymentRequestFactoryTest extends TestCase
{
    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(42)
            ->setShortId('o_ABC123')
            ->setEventId(7)
            ->setCurrency('PLN')
            ->setTotalGross(200.0)
            ->setEmail('anna.kowalska@example.com')
            ->setFirstName('Anna')
            ->setLastName('Kowalska')
            ->setLocale('pl')
            ->setOrderItems(collect([
                (new OrderItemDomainObject)
                    ->setItemName('Udział osoby')
                    ->setPrice(100.0)
                    ->setQuantity(2),
            ]));
    }

    public function test_it_maps_an_order_into_a_provider_request(): void
    {
        config(['app.frontend_url' => 'https://zapisy.example.org']);

        $request = (new PaymentRequestFactory)->fromOrder(
            $this->order(),
            PaymentProviders::PAYU,
            eventId: 7,
            buyerIp: '203.0.113.9',
        );

        $this->assertSame(42, $request->orderId);
        $this->assertSame('o_ABC123', $request->orderShortId);
        $this->assertSame('PLN', $request->currencyCode);
        $this->assertSame(200.00, $request->amount->toFloat());
        $this->assertSame('anna.kowalska@example.com', $request->buyerEmail);
        $this->assertSame('Anna', $request->buyerFirstName);
        $this->assertSame('Kowalska', $request->buyerLastName);
        $this->assertSame('pl', $request->buyerLanguage);
        $this->assertSame('203.0.113.9', $request->buyerIp);
        $this->assertSame(
            'https://zapisy.example.org/checkout/7/o_ABC123/payment_return',
            $request->returnUrl
        );
        $this->assertStringContainsString('/api/public/webhooks/payments/payu', (string) $request->notifyUrl);
    }

    public function test_it_maps_order_items_into_provider_line_items(): void
    {
        $request = (new PaymentRequestFactory)->fromOrder($this->order(), PaymentProviders::PAYU, eventId: 7);

        $this->assertCount(1, $request->products);
        $this->assertSame('Udział osoby', $request->products[0]['name']);
        $this->assertSame(2, $request->products[0]['quantity']);
        $this->assertSame(100.00, $request->products[0]['unitPrice']->toFloat());
        $this->assertSame('PLN', $request->products[0]['unitPrice']->getMoney()->getCurrency()->getCurrencyCode());
    }

    public function test_it_falls_back_to_a_generic_name_and_handles_a_missing_item_list(): void
    {
        $order = (new OrderDomainObject)
            ->setId(43)
            ->setShortId('o_NOITEMS')
            ->setCurrency('PLN')
            ->setTotalGross(0.0)
            ->setOrderItems(collect([
                (new OrderItemDomainObject)->setPrice(0.0)->setQuantity(1),
            ]));

        $request = (new PaymentRequestFactory)->fromOrder($order, PaymentProviders::PAYU, eventId: 7);
        $this->assertSame('Ticket', $request->products[0]['name']);

        // No items loaded at all must not blow up the checkout.
        $empty = (new PaymentRequestFactory)->fromOrder(
            (new OrderDomainObject)->setId(44)->setShortId('o_EMPTY')->setCurrency('PLN')->setTotalGross(0.0),
            PaymentProviders::PAYU,
            eventId: 7,
        );

        $this->assertSame([], $empty->products);
    }
}
