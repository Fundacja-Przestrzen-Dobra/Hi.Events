<?php

namespace HiEvents\Services\Domain\Payment\Gateway;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use RuntimeException;

/**
 * Resolves a PaymentGatewayInterface implementation for a provider value.
 *
 * Provider implementations are declared in config/malzenstwo.php so adding a
 * provider is a configuration change plus one class — no checkout edits.
 */
class PaymentGatewayRegistry
{
    /**
     * @param array<string, class-string<PaymentGatewayInterface>> $gateways provider value => class
     */
    public function __construct(
        private readonly array $gateways,
    ) {
    }

    public static function fromConfig(): self
    {
        /** @var array<string, class-string<PaymentGatewayInterface>> $gateways */
        $gateways = config('malzenstwo.payment_gateways', []);

        return new self($gateways);
    }

    public function get(PaymentProviders $provider): PaymentGatewayInterface
    {
        $class = $this->gateways[$provider->value] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                sprintf('No payment gateway is registered for provider [%s]', $provider->value)
            );
        }

        /** @var PaymentGatewayInterface $gateway */
        $gateway = app($class);

        return $gateway;
    }

    /**
     * Providers that can actually be offered right now: configured credentials
     * and a supported currency.
     *
     * @return array<int, PaymentGatewayInterface>
     */
    public function availableFor(string $currency): array
    {
        $available = [];

        foreach (array_keys($this->gateways) as $providerValue) {
            $provider = PaymentProviders::tryFrom($providerValue);

            if ($provider === null) {
                continue;
            }

            $gateway = $this->get($provider);

            if ($gateway->isConfigured() && $gateway->supportsCurrency($currency)) {
                $available[] = $gateway;
            }
        }

        return $available;
    }

    /**
     * @return array<int, string>
     */
    public function registeredProviderValues(): array
    {
        return array_keys($this->gateways);
    }
}
