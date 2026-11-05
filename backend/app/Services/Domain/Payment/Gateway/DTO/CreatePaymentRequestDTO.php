<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

use HiEvents\Values\MoneyValue;

/**
 * Everything a gateway needs to start a payment. The amount always comes from
 * the persisted order — never from the client request.
 */
final class CreatePaymentRequestDTO
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderShortId,
        public readonly MoneyValue $amount,
        public readonly string $currencyCode,
        public readonly string $description,
        public readonly ?string $buyerEmail = null,
        public readonly ?string $buyerFirstName = null,
        public readonly ?string $buyerLastName = null,
        public readonly ?string $buyerLanguage = null,
        public readonly ?string $buyerIp = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $notifyUrl = null,
        /** @var array<int, array{name: string, unitPrice: MoneyValue, quantity: int}> */
        public readonly array $products = [],
    ) {
    }
}
