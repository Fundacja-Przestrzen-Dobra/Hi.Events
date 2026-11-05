<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

/**
 * Provider-agnostic payment fact derived from a verified webhook.
 */
final class PaymentEventDTO
{
    public function __construct(
        public readonly string $providerReference,
        public readonly PaymentEventStatus $status,
        public readonly ?string $orderShortId = null,
        /** Amount in the currency's minor unit, when the provider tells us. */
        public readonly ?int $amountMinor = null,
        public readonly ?string $currencyCode = null,
        /** Stable key for duplicate/replay protection (provider event or status id). */
        public readonly ?string $eventKey = null,
        /** @var array<string, mixed> */
        public readonly array $rawPayload = [],
    ) {
    }
}
