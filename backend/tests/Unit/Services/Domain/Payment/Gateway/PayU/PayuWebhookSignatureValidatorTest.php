<?php

namespace Tests\Unit\Services\Domain\Payment\Gateway\PayU;

use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuWebhookSignatureValidator;
use PHPUnit\Framework\TestCase;

class PayuWebhookSignatureValidatorTest extends TestCase
{
    private const SECOND_KEY = 'test-second-key';

    private string $body = '{"order":{"orderId":"ABC123","status":"COMPLETED","extOrderId":"o_1"}}';

    private function validator(string $key = self::SECOND_KEY): PayuWebhookSignatureValidator
    {
        return new PayuWebhookSignatureValidator($key);
    }

    private function signatureHeader(string $body, string $algorithm = 'MD5'): string
    {
        $hash = $algorithm === 'MD5'
            ? md5($body . self::SECOND_KEY)
            : hash('sha256', $body . self::SECOND_KEY);

        return sprintf('signature=%s;algorithm=%s;content=DOCUMENT', $hash, $algorithm);
    }

    public function test_it_accepts_a_matching_md5_signature(): void
    {
        $this->assertTrue(
            $this->validator()->isValid($this->body, $this->signatureHeader($this->body))
        );
    }

    public function test_it_accepts_a_matching_sha256_signature(): void
    {
        $this->assertTrue(
            $this->validator()->isValid($this->body, $this->signatureHeader($this->body, 'SHA-256'))
        );
    }

    public function test_it_rejects_a_tampered_body(): void
    {
        $header = $this->signatureHeader($this->body);

        $this->assertFalse(
            $this->validator()->isValid($this->body . ' ', $header)
        );
    }

    public function test_it_rejects_a_signature_made_with_another_key(): void
    {
        $foreignValidator = new PayuWebhookSignatureValidator('another-key');
        $header = sprintf('signature=%s;algorithm=MD5;content=DOCUMENT', md5($this->body . 'another-key'));

        // The other key's own validator accepts it; ours must not.
        $this->assertTrue($foreignValidator->isValid($this->body, $header));
        $this->assertFalse($this->validator()->isValid($this->body, $header));
    }

    public function test_it_rejects_a_missing_or_malformed_header(): void
    {
        $this->assertFalse($this->validator()->isValid($this->body, null));
        $this->assertFalse($this->validator()->isValid($this->body, ''));
        $this->assertFalse($this->validator()->isValid($this->body, 'algorithm=MD5'));
    }

    public function test_it_rejects_an_unsupported_algorithm(): void
    {
        $header = sprintf('signature=%s;algorithm=CRC32;content=DOCUMENT', md5($this->body . self::SECOND_KEY));

        $this->assertFalse($this->validator()->isValid($this->body, $header));
    }

    public function test_it_fails_closed_without_a_configured_second_key(): void
    {
        $this->assertFalse(
            $this->validator('')->isValid($this->body, $this->signatureHeader($this->body))
        );
    }
}
