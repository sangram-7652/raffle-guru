<?php

namespace App\Services\Payments;

/**
 * A payment-gateway configuration that must never be allowed to boot —
 * see App\Providers\PaymentServiceProvider (P11.16). Distinct from a
 * runtime payment failure (there is no PaymentException for those; each
 * failure mode is handled inline where it happens, e.g. the webhook's
 * signature check): this represents the environment itself being wrong,
 * detectable only once the app actually boots, which is exactly what
 * \RuntimeException is for — same base class as
 * App\Services\Draw\DrawException.
 */
class PaymentGatewayConfigurationException extends \RuntimeException {}
