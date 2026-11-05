<?php

/*
 * Fundacja Przestrzeń Dobra additions (see CHANGES.md).
 *
 * Payment providers are pluggable: each provider value from PaymentProviders
 * maps to a class implementing PaymentGatewayInterface. Adding a provider is a
 * configuration entry plus the implementation — checkout code stays untouched.
 * An implementation whose credentials are missing reports isConfigured() = false
 * and is simply not offered.
 */

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Services\Domain\Payment\Gateway\Offline\OfflinePaymentGateway;

return [
    'payment_gateways' => [
        PaymentProviders::OFFLINE->value => OfflinePaymentGateway::class,
        // PayU: registered here once the adapter lands (see CHANGES.md "Planned").
        // Stripe: wrapped in the same contract before it can be registered here.
    ],
];
