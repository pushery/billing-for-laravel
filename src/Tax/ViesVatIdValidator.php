<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Support\Facades\Http;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Enums\VatIdValidation;
use Throwable;

/**
 * Validates a VAT id against the EU VIES REST service. A well-formed id splits into a two-letter country code
 * and the number; VIES answers whether it is registered.
 *
 * If VIES cannot be reached (down, timeout, throttled) the result is Unavailable — NOT Invalid — so a
 * temporary outage never wrongly denies a legitimate business its reverse charge. The caller decides what an
 * unproven id means; this package's default is conservative (no reverse charge → charge VAT), so a VIES outage
 * never causes an under-charge. The endpoint is a fixed EU host, so there is no user-controlled request target.
 */
final class ViesVatIdValidator implements VatIdValidator
{
    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

    public function validate(?string $vatId): VatIdValidation
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $vatId ?? '') ?? '');

        if (preg_match('/^([A-Z]{2})([0-9A-Z]{2,12})$/', $normalized, $matches) !== 1) {
            return VatIdValidation::Invalid; // malformed — not a VAT id at all
        }

        try {
            $response = Http::timeout(5)->get(self::ENDPOINT.'/'.$matches[1].'/vat/'.$matches[2]);
        } catch (Throwable) {
            return VatIdValidation::Unavailable;
        }

        if (! $response->successful()) {
            return VatIdValidation::Unavailable;
        }

        // VIES answers HTTP 200 even for a transient member-state outage, signaling it in `userError`. That
        // is NOT a verdict on the id, so treat it as Unavailable (conservative) — never Invalid: an outage
        // must neither reject a real business nor, via the caller, grant an unearned zero-rate.
        $userError = $response->json('userError');
        $transient = ['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'GLOBAL_MAX_CONCURRENT_REQ', 'TIMEOUT', 'SERVER_BUSY'];

        if (is_string($userError) && in_array($userError, $transient, true)) {
            return VatIdValidation::Unavailable;
        }

        return $response->json('isValid') === true ? VatIdValidation::Valid : VatIdValidation::Invalid;
    }
}
