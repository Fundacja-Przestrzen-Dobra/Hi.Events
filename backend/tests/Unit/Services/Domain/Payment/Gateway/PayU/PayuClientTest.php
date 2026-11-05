<?php

namespace Tests\Unit\Services\Domain\Payment\Gateway\PayU;

use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuClient;
use Tests\TestCase;

class PayuClientTest extends TestCase
{
    private function client(): PayuClient
    {
        return new PayuClient(
            posId: '300746',
            clientId: '300746',
            clientSecret: 'secret',
            baseUrl: 'https://secure.snd.payu.com',
        );
    }

    public function test_it_maps_payu_statuses_to_internal_ones(): void
    {
        $client = $this->client();

        $this->assertSame(PaymentEventStatus::PAID, $client->mapStatus('COMPLETED'));
        $this->assertSame(PaymentEventStatus::PAID, $client->mapStatus('completed'));
        $this->assertSame(PaymentEventStatus::FAILED, $client->mapStatus('CANCELED'));
        $this->assertSame(PaymentEventStatus::FAILED, $client->mapStatus('REJECTED'));
        $this->assertSame(PaymentEventStatus::REFUNDED, $client->mapStatus('REFUND'));
        $this->assertSame(PaymentEventStatus::PENDING, $client->mapStatus('NEW'));
        $this->assertSame(PaymentEventStatus::PENDING, $client->mapStatus('PENDING'));
        $this->assertSame(PaymentEventStatus::PENDING, $client->mapStatus('something-unexpected'));
    }
}
