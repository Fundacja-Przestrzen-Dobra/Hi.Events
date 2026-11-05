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
use HiEvents\Services\Domain\Payment\Gateway\PayU\PayuGateway;

return [
    'payment_gateways' => [
        PaymentProviders::OFFLINE->value => OfflinePaymentGateway::class,
        PaymentProviders::PAYU->value => PayuGateway::class,
        // Stripe: wrapped in the same contract before it can be registered here.
    ],

    'payu' => [
        'pos_id' => env('PAYU_POS_ID'),
        'client_id' => env('PAYU_CLIENT_ID'),
        'client_secret' => env('PAYU_CLIENT_SECRET'),
        'second_key' => env('PAYU_SECOND_KEY'),
        'sandbox' => (bool) env('PAYU_SANDBOX', true),
    ],
];
