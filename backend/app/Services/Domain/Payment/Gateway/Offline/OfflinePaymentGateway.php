<?php

namespace HiEvents\Services\Domain\Payment\Gateway\Offline;

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
use RuntimeException;

/**
 * Offline payments: the buyer transfers the money outside the platform and staff
 * mark the order as paid. Expressed as a gateway so checkout has a single code
 * path; it never receives webhooks and never issues provider refunds.
 */
class OfflinePaymentGateway implements PaymentGatewayInterface
{
    public function provider(): PaymentProviders
    {
        return PaymentProviders::OFFLINE;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    public function createPayment(CreatePaymentRequestDTO $request): CreatePaymentResponseDTO
    {
        return new CreatePaymentResponseDTO(
            providerReference: 'offline:' . $request->orderShortId,
        );
    }

    public function fetchStatus(string $providerReference): PaymentStatusDTO
    {
        return new PaymentStatusDTO(
            providerReference: $providerReference,
            status: PaymentEventStatus::PENDING,
        );
    }

    public function refund(RefundRequestDTO $request): RefundResultDTO
    {
        return new RefundResultDTO(
            success: false,
            message: 'Offline refunds are recorded manually — nothing to call at the provider.',
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): PaymentEventDTO
    {
        throw new RuntimeException('Offline payments have no webhook source.');
    }
}
