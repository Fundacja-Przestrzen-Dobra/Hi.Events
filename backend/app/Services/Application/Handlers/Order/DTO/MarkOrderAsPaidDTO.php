<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class MarkOrderAsPaidDTO extends BaseDTO
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $orderId,
        /**
         * Set by online payment providers: their confirmation settles an order that is
         * still RESERVED (such an order never waits for an offline transfer).
         */
        public readonly bool $allowReservedOrder = false,
    ) {}
}
