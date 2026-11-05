<?php

namespace Tests\Unit\Services\Domain\Payment\Gateway\PayU;

use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuClient;
use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuGateway;
use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuWebhookSignatureValidator;
use HiEvents\Values\MoneyValue;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayuGatewayTest extends TestCase
{
    private function gateway(): PayuGateway
    {
        return new PayuGateway(
            new PayuClient('300746', '300746', 'secret', 'https://secure.snd.payu.com'),
            new PayuWebhookSignatureValidator('second-key'),
        );
    }

    public function test_it_sends_amounts_in_grosze_and_the_order_short_id_as_ext_order_id(): void
    {
        Http::fake([
            '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'token-1'], 200),
            '*/api/v2_1/orders' => Http::response([
                'orderId' => 'PAYU-ORDER-1',
                'redirectUri' => 'https://secure.snd.payu.com/pay/?orderId=PAYU-ORDER-1',
            ], 201),
        ]);

        $response = $this->gateway()->createPayment(new CreatePaymentRequestDTO(
            orderId: 10,
            orderShortId: 'o_ABC123',
            amount: MoneyValue::fromFloat(200.00, 'PLN'),
            currencyCode: 'PLN',
            description: 'Warsztat dla małżeństw',
            buyerEmail: 'anna@example.com',
            buyerFirstName: 'Anna',
            buyerLastName: 'Kowalska',
            buyerLanguage: 'pl',
            buyerIp: '192.0.2.10',
            returnUrl: 'https://zapisy.example.org/checkout/o_ABC123/payment',
            notifyUrl: 'https://zapisy.example.org/api/public/webhooks/payments/payu',
            products: [
                ['name' => 'Udział osoby', 'unitPrice' => MoneyValue::fromFloat(100.00, 'PLN'), 'quantity' => 2],
            ],
        ));

        $this->assertSame('PAYU-ORDER-1', $response->providerReference);
        $this->assertTrue($response->requiresRedirect());

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_ends_with($request->url(), '/api/v2_1/orders')) {
                return false;
            }

            $data = $request->data();

            return $data['totalAmount'] === '20000'
                && $data['extOrderId'] === 'o_ABC123'
                && $data['merchantPosId'] === '300746'
                && $data['currencyCode'] === 'PLN'
                && $data['products'][0]['unitPrice'] === '10000'
                && $data['products'][0]['quantity'] === '2'
                && $data['buyer']['email'] === 'anna@example.com';
        });
    }

    public function test_it_parses_a_completed_notification_into_a_paid_event(): void
    {
        $body = json_encode([
            'order' => [
                'orderId' => 'PAYU-ORDER-1',
                'extOrderId' => 'o_ABC123',
                'status' => 'COMPLETED',
                'totalAmount' => '20000',
                'currencyCode' => 'PLN',
            ],
        ]);

        $request = Request::create('/api/public/webhooks/payments/payu', 'POST', [], [], [], [], $body);
        $request->headers->set('OpenPayu-Signature', 'signature=' . md5($body . 'second-key') . ';algorithm=MD5;content=DOCUMENT');

        $gateway = $this->gateway();

        $this->assertTrue($gateway->verifyWebhook($request));

        $event = $gateway->parseWebhook($request);

        $this->assertSame(PaymentEventStatus::PAID, $event->status);
        $this->assertSame('PAYU-ORDER-1', $event->providerReference);
        $this->assertSame('o_ABC123', $event->orderShortId);
        $this->assertSame(20000, $event->amountMinor);
        $this->assertSame('PAYU-ORDER-1:COMPLETED', $event->eventKey);
    }

    public function test_it_rejects_a_notification_with_a_bad_signature(): void
    {
        $request = Request::create('/api/public/webhooks/payments/payu', 'POST', [], [], [], [], '{"order":{}}');
        $request->headers->set('OpenPayu-Signature', 'signature=deadbeef;algorithm=MD5;content=DOCUMENT');

        $this->assertFalse($this->gateway()->verifyWebhook($request));
    }
}
