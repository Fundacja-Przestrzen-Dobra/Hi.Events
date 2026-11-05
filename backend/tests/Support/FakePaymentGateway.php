<?php

namespace Tests\Support;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentResponseDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentStatusDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundResultDTO;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayInterface;
use Illuminate\Http\Request;

/**
 * In-memory gateway for tests: no network, deterministic answers, and a way to
 * simulate any notification payload.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    public array $createdPayments = [];

    public bool $configured = true;

    public bool $signatureValid = true;

    public ?PaymentEventDTO $nextEvent = null;

    public function __construct(
        private readonly PaymentProviders $provider = PaymentProviders::STRIPE,
    ) {
    }

    public function provider(): PaymentProviders
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    public function createPayment(CreatePaymentRequestDTO $request): CreatePaymentResponseDTO
    {
        $this->createdPayments[] = $request;

        return new CreatePaymentResponseDTO(
            providerReference: 'fake-' . $request->orderShortId,
            redirectUrl: 'https://payments.example.test/fake-' . $request->orderShortId,
            rawResponse: ['fake' => true],
        );
    }

    public function fetchStatus(string $providerReference): PaymentStatusDTO
    {
        return new PaymentStatusDTO(
            providerReference: $providerReference,
            status: $this->nextEvent?->status ?? PaymentEventStatus::PENDING,
        );
    }

    public function refund(RefundRequestDTO $request): RefundResultDTO
    {
        return new RefundResultDTO(success: true, providerReference: 'fake-refund');
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->signatureValid;
    }

    public function parseWebhook(Request $request): PaymentEventDTO
    {
        if ($this->nextEvent !== null) {
            return $this->nextEvent;
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        return new PaymentEventDTO(
            providerReference: (string) ($payload['reference'] ?? 'fake-ref'),
            status: PaymentEventStatus::from($payload['status'] ?? 'PENDING'),
            orderShortId: $payload['order_short_id'] ?? null,
            amountMinor: $payload['amount_minor'] ?? null,
            currencyCode: $payload['currency'] ?? null,
            eventKey: (string) ($payload['event_key'] ?? 'fake-key'),
            rawPayload: $payload,
        );
    }
}
