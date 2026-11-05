<?php

namespace HiEvents\Services\Domain\Payment\Gateway\PayU;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentResponseDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentStatusDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundResultDTO;
use HiEvents\Services\Domain\Payment\Gateway\PaymentGatewayInterface;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * PayU as a redirect gateway: the buyer pays on PayU's hosted page, we only ever
 * hold the provider order id.
 *
 * Dependencies are resolved lazily so the class is container-constructible while
 * remaining injectable in tests.
 */
class PayuGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly ?PayuClient $injectedClient = null,
        private readonly ?PayuWebhookSignatureValidator $injectedValidator = null,
    ) {
    }

    public function provider(): PaymentProviders
    {
        return PaymentProviders::PAYU;
    }

    public function isConfigured(): bool
    {
        return (string) config('malzenstwo.payu.pos_id') !== ''
            && (string) config('malzenstwo.payu.client_id') !== ''
            && (string) config('malzenstwo.payu.client_secret') !== ''
            && (string) config('malzenstwo.payu.second_key') !== '';
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'PLN';
    }

    public function createPayment(CreatePaymentRequestDTO $request): CreatePaymentResponseDTO
    {
        return $this->client()->createOrder($request);
    }

    public function fetchStatus(string $providerReference): PaymentStatusDTO
    {
        return $this->client()->getOrder($providerReference);
    }

    public function refund(RefundRequestDTO $request): RefundResultDTO
    {
        return $this->client()->refund($request->providerReference, $request);
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->validator()->isValid(
            $request->getContent(),
            $request->header('OpenPayu-Signature')
        );
    }

    public function parseWebhook(Request $request): PaymentEventDTO
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('PayU notification is not valid JSON.');
        }

        $order = $payload['order'] ?? [];

        $orderId = (string) ($order['orderId'] ?? '');
        $status = (string) ($order['status'] ?? '');

        if ($orderId === '' || $status === '') {
            throw new RuntimeException('PayU notification does not carry an order id and status.');
        }

        return new PaymentEventDTO(
            providerReference: $orderId,
            status: $this->client()->mapStatus($status),
            orderShortId: isset($order['extOrderId']) ? (string) $order['extOrderId'] : null,
            amountMinor: isset($order['totalAmount']) ? (int) $order['totalAmount'] : null,
            currencyCode: isset($order['currencyCode']) ? (string) $order['currencyCode'] : null,
            eventKey: $orderId . ':' . strtoupper($status),
            rawPayload: $payload,
        );
    }

    private function client(): PayuClient
    {
        return $this->injectedClient ?? PayuClient::fromConfig();
    }

    private function validator(): PayuWebhookSignatureValidator
    {
        return $this->injectedValidator ?? PayuWebhookSignatureValidator::fromConfig();
    }
}
