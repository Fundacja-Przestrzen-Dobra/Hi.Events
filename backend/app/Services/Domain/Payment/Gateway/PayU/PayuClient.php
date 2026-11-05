<?php

namespace HiEvents\Services\Domain\Payment\Gateway\PayU;

use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\CreatePaymentResponseDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentEventStatus;
use HiEvents\Services\Domain\Payment\Gateway\DTO\PaymentStatusDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundRequestDTO;
use HiEvents\Services\Domain\Payment\Gateway\DTO\RefundResultDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the PayU GPO Europe REST API.
 *
 * Amounts are passed as MoneyValue and converted to minor units (grosze) here —
 * PayU expects the lowest currency unit as a string. Nothing in this class
 * trusts a client-supplied amount: callers build the value from the order.
 */
class PayuClient
{
    private const TOKEN_CACHE_KEY = 'payu:oauth_token';

    public function __construct(
        private readonly string $posId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseUrl = 'https://secure.payu.com',
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            posId: (string) config('malzenstwo.payu.pos_id'),
            clientId: (string) config('malzenstwo.payu.client_id'),
            clientSecret: (string) config('malzenstwo.payu.client_secret'),
            baseUrl: config('malzenstwo.payu.sandbox', true)
                ? 'https://secure.snd.payu.com'
                : 'https://secure.payu.com',
        );
    }

    public function createOrder(CreatePaymentRequestDTO $request): CreatePaymentResponseDTO
    {
        $payload = [
            'notifyUrl' => $request->notifyUrl,
            'continueUrl' => $request->returnUrl,
            'customerIp' => $request->buyerIp,
            'merchantPosId' => $this->posId,
            'description' => $request->description,
            'currencyCode' => $request->currencyCode,
            'totalAmount' => (string) $request->amount->toMinorUnit(),
            'extOrderId' => $request->orderShortId,
            'products' => array_map(
                fn (array $product): array => [
                    'name' => $product['name'],
                    'unitPrice' => (string) $product['unitPrice']->toMinorUnit(),
                    'quantity' => (string) $product['quantity'],
                ],
                $request->products
            ),
        ];

        if ($request->buyerEmail !== null) {
            $payload['buyer'] = array_filter([
                'email' => $request->buyerEmail,
                'firstName' => $request->buyerFirstName,
                'lastName' => $request->buyerLastName,
                'language' => $request->buyerLanguage ?? 'pl',
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $response = $this->client()->post('/api/v2_1/orders', $payload);

        // PayU answers 302 with the hosted-page URL in the Location header for the
        // redirect flow; some configurations return 201 with redirectUri in the body.
        $body = $response->json() ?? [];
        $redirectUri = $body['redirectUri'] ?? $response->header('Location');
        $orderId = $body['orderId'] ?? null;

        if ($orderId === null || $redirectUri === null || $redirectUri === '') {
            throw new RuntimeException(
                'PayU did not return an order id and redirect URL: ' . $response->body()
            );
        }

        return new CreatePaymentResponseDTO(
            providerReference: (string) $orderId,
            redirectUrl: (string) $redirectUri,
            rawResponse: $body,
        );
    }

    public function getOrder(string $payuOrderId): PaymentStatusDTO
    {
        $response = $this->client()->get('/api/v2_1/orders/' . rawurlencode($payuOrderId));
        $body = $response->json() ?? [];
        $order = $body['orders'][0] ?? [];

        return new PaymentStatusDTO(
            providerReference: $payuOrderId,
            status: $this->mapStatus((string) ($order['status'] ?? '')),
            rawResponse: $body,
        );
    }

    public function refund(string $payuOrderId, RefundRequestDTO $request): RefundResultDTO
    {
        $response = $this->client()->post(
            '/api/v2_1/orders/' . rawurlencode($payuOrderId) . '/refunds',
            [
                'description' => $request->description,
                'amount' => (string) $request->amount->toMinorUnit(),
                'currencyCode' => $request->amount->getMoney()->getCurrency()->getCurrencyCode(),
            ]
        );

        $body = $response->json() ?? [];

        return new RefundResultDTO(
            success: $response->successful(),
            providerReference: $body['refund']['refundId'] ?? null,
            message: $body['status']['statusDesc'] ?? null,
            rawResponse: $body,
        );
    }

    public function mapStatus(string $payuStatus): PaymentEventStatus
    {
        return match (strtoupper($payuStatus)) {
            'COMPLETED' => PaymentEventStatus::PAID,
            'CANCELED', 'REJECTED', 'TIMEOUT' => PaymentEventStatus::FAILED,
            'REFUND', 'REFUNDED' => PaymentEventStatus::REFUNDED,
            default => PaymentEventStatus::PENDING,
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post('/pl/standard/user/oauth/authorize', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('PayU OAuth failed: ' . $response->body());
        }

        // PayU tokens live ~12h; refresh well before that.
        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes(10));

        return $token;
    }
}
