<?php

namespace Tests\Unit\Services\Domain\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\Offline\OfflinePaymentGateway;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayRegistry;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayRegistryTest extends TestCase
{
    private function registry(): PaymentGatewayRegistry
    {
        return new PaymentGatewayRegistry([
            PaymentProviders::OFFLINE->value => OfflinePaymentGateway::class,
        ]);
    }

    public function test_it_resolves_a_registered_provider(): void
    {
        $gateway = $this->registry()->get(PaymentProviders::OFFLINE);

        $this->assertInstanceOf(OfflinePaymentGateway::class, $gateway);
        $this->assertSame(PaymentProviders::OFFLINE, $gateway->provider());
    }

    public function test_it_throws_for_an_unregistered_provider(): void
    {
        $this->expectException(RuntimeException::class);

        $this->registry()->get(PaymentProviders::PAYU);
    }

    public function test_it_offers_only_configured_providers_supporting_the_currency(): void
    {
        $available = $this->registry()->availableFor('PLN');

        $this->assertCount(1, $available);
        $this->assertSame(PaymentProviders::OFFLINE, $available[0]->provider());
    }

    public function test_offline_gateway_never_claims_paid_and_has_no_webhook(): void
    {
        $gateway = new OfflinePaymentGateway();

        $this->assertTrue($gateway->isConfigured());
        $this->assertTrue($gateway->supportsCurrency('PLN'));
        $this->assertFalse($gateway->verifyWebhook(new \Illuminate\Http\Request()));
        $this->assertSame(
            PaymentEventStatus::PENDING,
            $gateway->fetchStatus('offline:o_1')->status
        );
        $this->assertFalse(
            $gateway->refund(new \HiEvents\Services\Domain\Payment\Gateway\DTO\RefundRequestDTO(
                providerReference: 'offline:o_1',
                amount: \HiEvents\Values\MoneyValue::fromFloat(10.0, 'PLN'),
            ))->success
        );
    }
}
