<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

use HiEvents\Values\MoneyValue;

final class RefundRequestDTO
{
    public function __construct(
        public readonly string $providerReference,
        public readonly MoneyValue $amount,
        public readonly string $description = 'Refund',
    ) {
    }
}
