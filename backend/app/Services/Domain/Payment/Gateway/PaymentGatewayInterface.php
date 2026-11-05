<?php

namespace HiEvents\Services\Domain\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentResponseDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentStatusDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundResultDTO;
use Illuminate\Http\Request;

/**
 * Contract every payment provider implements.
 *
 * Providers are pluggable: the registry resolves an implementation by the
 * PaymentProviders value recorded on the order, so checkout code never talks to
 * a specific provider SDK. Redirect gateways (PayU, bank links) return a redirect
 * URL from createPayment(); embedded gateways may return a client secret instead.
 */
interface PaymentGatewayInterface
{
    public function provider(): PaymentProviders;

    /**
     * Whether the credentials for this provider are present in the environment.
     * An unconfigured provider is hidden from event settings instead of failing
     * at checkout.
     */
    public function isConfigured(): bool;

    /**
     * Whether this provider can charge in the given ISO currency.
     */
    public function supportsCurrency(string $currency): bool;

    public function createPayment(CreatePaymentRequestDTO $request): CreatePaymentResponseDTO;

    public function fetchStatus(string $providerReference): PaymentStatusDTO;

    public function refund(RefundRequestDTO $request): RefundResultDTO;

    /**
     * Signature/authenticity check of an incoming notification. Must be computed
     * over the raw request body and compared in constant time.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * Translate a verified notification into a provider-agnostic event.
     */
    public function parseWebhook(Request $request): PaymentEventDTO;
}
