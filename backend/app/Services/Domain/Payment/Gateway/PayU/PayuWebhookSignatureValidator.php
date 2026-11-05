<?php

namespace HiEvents\Services\Domain\Payment\Gateway\PayU;

/**
 * Verifies the OpenPayu-Signature header of a notification.
 *
 * Header shape: `signature=<hash>;algorithm=MD5;content=DOCUMENT`
 * The hash is computed over the RAW request body concatenated with the POS second
 * key, so the body must never be re-encoded before hashing.
 */
class PayuWebhookSignatureValidator
{
    public function __construct(
        private readonly string $secondKey,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('malzenstwo.payu.second_key'));
    }

    public function isValid(string $rawBody, ?string $signatureHeader): bool
    {
        if ($this->secondKey === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $params = $this->parseHeader($signatureHeader);
        $signature = $params['signature'] ?? null;
        $algorithm = strtoupper($params['algorithm'] ?? 'MD5');

        if ($signature === null || $signature === '') {
            return false;
        }

        $expected = match ($algorithm) {
            'MD5' => md5($rawBody . $this->secondKey),
            'SHA-256', 'SHA256' => hash('sha256', $rawBody . $this->secondKey),
            default => null,
        };

        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeader(string $header): array
    {
        $params = [];

        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) === 2) {
                $params[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $params;
    }
}
