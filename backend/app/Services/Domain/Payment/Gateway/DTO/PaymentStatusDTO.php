<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

final class PaymentStatusDTO
{
    public function __construct(
        public readonly string $providerReference,
        public readonly PaymentEventStatus $status,
        /** @var array<string, mixed> */
        public readonly array $rawResponse = [],
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentEventStatus::PAID;
    }
}
