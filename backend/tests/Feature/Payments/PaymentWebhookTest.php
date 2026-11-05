<?php

namespace Tests\Feature\Payments;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'malzenstwo.payment_gateways' => [
                PaymentProviders::STRIPE->value => FakePaymentGateway::class,
            ],
        ]);
    }

    private function body(string $eventKey = 'evt-1'): string
    {
        return json_encode([
            'reference' => 'fake-ref-1',
            'status' => 'PAID',
            'order_short_id' => null,
            'amount_minor' => 20000,
            'currency' => 'PLN',
            'event_key' => $eventKey,
        ]);
    }

    public function test_it_rejects_a_notification_with_an_invalid_signature(): void
    {
        $this->app->bind(FakePaymentGateway::class, function (): FakePaymentGateway {
            $gateway = new FakePaymentGateway();
            $gateway->signatureValid = false;

            return $gateway;
        });

        $response = $this->postJson('/public/webhooks/payments/STRIPE', json_decode($this->body(), true));

        $this->assertSame(401, $response->status(), 'BODY: ' . $response->getContent());

        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public function test_it_stores_a_valid_notification_and_is_idempotent(): void
    {
        $payload = json_decode($this->body(), true);

        $response = $this->postJson('/public/webhooks/payments/STRIPE', $payload);

        $this->assertSame(200, $response->status(), 'BODY: ' . $response->getContent());
        $this->assertSame('ok', $response->json('status'));

        $this->assertSame(1, DB::table('payment_webhook_events')->count());

        $row = DB::table('payment_webhook_events')->first();
        $this->assertSame('STRIPE', $row->provider);
        $this->assertSame('evt-1', $row->event_key);
        $this->assertNotNull($row->processed_at);

        // The same notification delivered again must not be applied twice.
        $this->postJson('/public/webhooks/payments/STRIPE', $payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, DB::table('payment_webhook_events')->count());
    }

    public function test_it_handles_an_unknown_provider(): void
    {
        $this->postJson('/public/webhooks/payments/NOPE', json_decode($this->body(), true))
            ->assertStatus(404);
    }

    public function test_it_rejects_a_webhook_for_the_offline_provider(): void
    {
        $this->postJson('/public/webhooks/payments/OFFLINE', json_decode($this->body(), true))
            ->assertStatus(404);
    }

    public function test_status_endpoint_reports_a_missing_order(): void
    {
        $this->getJson('/public/events/1/order/o_missing/payments/status')
            ->assertStatus(404);
    }
}
