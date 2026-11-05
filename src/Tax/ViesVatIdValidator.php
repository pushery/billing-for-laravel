<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Pushery\Billing\Contracts\ChecksVatIds;
use Pushery\Billing\Contracts\VatIdValidator;
use Pushery\Billing\Enums\VatIdValidation;
use Pushery\Billing\ValueObjects\VatIdCheck;
use Throwable;

/**
 * Validates a VAT id against the EU VIES REST service. A well-formed id splits into a two-letter country code
 * and the number; VIES answers whether it is registered, and with the name, the address and the moment of its
 * answer where the member state supplies them.
 *
 * If VIES cannot be reached (down, timeout, throttled) the result is Unavailable — not Invalid — so a
 * temporary outage never wrongly denies a legitimate business its reverse charge. The caller decides what an
 * unproven id means; this package's default is conservative (no reverse charge → charge VAT), so a VIES outage
 * never causes an under-charge. The endpoint is a fixed EU host, so there is no user-controlled request target.
 */
final class ViesVatIdValidator implements ChecksVatIds, VatIdValidator
{
    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

    /**
     * The `userError` values that judge nothing about the number. VIES answers HTTP 200 even for a transient
     * member-state outage and signals it here; and a requester the register does not accept comes back as
     * `isValid: false` with INVALID_REQUESTER_INFO, about the requester and not the number. Each is Unavailable
     * (conservative) — never Invalid: it must neither reject a real business nor, via the caller, grant an
     * unearned zero-rate. A refused requester is marked on the check, so a caller can tell it from an outage
     * and ask again without one.
     */
    private const array NO_VERDICT = [
        'MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'GLOBAL_MAX_CONCURRENT_REQ', 'TIMEOUT', 'SERVER_BUSY',
        'INVALID_REQUESTER_INFO',
    ];

    /** What VIES writes in place of a name or an address the member state does not supply. */
    private const string WITHHELD = '---';

    public function validate(?string $vatId): VatIdValidation
    {
        return $this->check($vatId)->verdict;
    }

    public function check(?string $vatId, ?string $requesterVatId = null): VatIdCheck
    {
        $number = $this->split($vatId);

        if ($number === null) {
            return new VatIdCheck(VatIdValidation::Invalid); // malformed — not a VAT id at all
        }

        $url = self::ENDPOINT.'/'.$number[0].'/vat/'.$number[1];
        $requester = $requesterVatId === null ? null : $this->split($requesterVatId);

        if ($requesterVatId !== null && $requester === null) {
            throw new InvalidArgumentException('The requester VAT id is not a VAT id, so the register cannot be asked a qualified request.');
        }

        try {
            $response = $requester === null
                ? Http::timeout(5)->get($url)
                : Http::timeout(5)->get($url, ['requesterMemberStateCode' => $requester[0], 'requesterNumber' => $requester[1]]);
        } catch (Throwable) {
            return new VatIdCheck(VatIdValidation::Unavailable);
        }

        if (! $response->successful()) {
            return new VatIdCheck(VatIdValidation::Unavailable);
        }

        $userError = $response->json('userError');

        if (is_string($userError) && in_array($userError, self::NO_VERDICT, true)) {
            return new VatIdCheck(VatIdValidation::Unavailable, requesterRefused: $userError === 'INVALID_REQUESTER_INFO');
        }

        return new VatIdCheck(
            $response->json('isValid') === true ? VatIdValidation::Valid : VatIdValidation::Invalid,
            name: $this->supplied($response->json('name')),
            address: $this->supplied($response->json('address')),
            checkedAt: $this->moment($response->json('requestDate')),
            reference: $this->supplied($response->json('requestIdentifier')),
        );
    }

    /**
     * A VAT id as its country code and its number, whitespace of any kind and case taken off first, or null for
     * one that is no VAT id.
     *
     * @return array{string, string}|null
     */
    private function split(?string $vatId): ?array
    {
        $normalized = strtoupper(preg_replace('/\s+/u', '', $vatId ?? '') ?? '');

        // Every VAT id of the union carries a digit, so a word that only looks like a country code and a number is none.
        if (preg_match('/^([A-Z]{2})(?=[0-9A-Z]*[0-9])([0-9A-Z]{2,12})$/', $normalized, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /** A detail of the answer, or null where VIES supplied none. */
    private function supplied(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || $value === self::WITHHELD ? null : $value;
    }

    /** The moment of the answer, or null where VIES named none it can be read as. */
    private function moment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
