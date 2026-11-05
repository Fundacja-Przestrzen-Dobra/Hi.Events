<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

final class RefundResultDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        /** @var array<string, mixed> */
        public readonly array $rawResponse = [],
    ) {
    }
}
