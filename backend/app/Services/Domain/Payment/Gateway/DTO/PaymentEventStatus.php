<?php

namespace HiEvents\Services\Domain\Payment\Gateway\DTO;

enum PaymentEventStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case REFUNDED = 'REFUNDED';
}
