<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

final class CreatePaymentResponseDTO
{
    public function __construct(
        /** Provider-side identifier for the payment (PayU orderId, Stripe payment intent id, ...). */
        public readonly string $providerReference,
        /** Redirect gateways: where to send the buyer. */
        public readonly ?string $redirectUrl = null,
        /** Embedded gateways: client-side secret. */
        public readonly ?string $clientSecret = null,
        /** @var array<string, mixed> */
        public readonly array $rawResponse = [],
    ) {
    }

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
