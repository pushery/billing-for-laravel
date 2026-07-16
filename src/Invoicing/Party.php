<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

/**
 * A seller or buyer party on an e-invoice, normalized from loosely-typed config/JSON into the fields
 * an EN 16931 party needs. Anything missing degrades to a safe default (empty string, or Germany for
 * an absent country) rather than throwing — a malformed address must not break invoice rendering.
 */
final readonly class Party
{
    /** EAS (Electronic Address Scheme) code for an email address — the truest routing address. */
    private const string EAS_EMAIL = 'EM';

    /** EAS code for a German VAT identification number (USt-IdNr.) used as an electronic address. */
    private const string EAS_GERMAN_VAT = '9930';

    public function __construct(
        public string $name,
        public string $address,
        public string $postcode,
        public string $city,
        public string $country,
        public ?string $vatId,
        public ?string $endpointId,
        public string $endpointScheme,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $vatId = self::nonEmptyString($data, 'vat_id');
        [$endpointId, $endpointScheme] = self::resolveEndpoint($data, $vatId);

        return new self(
            name: self::string($data, 'name'),
            address: self::string($data, 'address'),
            postcode: self::string($data, 'postcode'),
            city: self::string($data, 'city'),
            country: self::string($data, 'country', 'DE'),
            vatId: $vatId,
            endpointId: $endpointId,
            endpointScheme: $endpointScheme,
        );
    }

    /**
     * Resolve the party's electronic address (BT-34 seller / BT-49 buyer). XRechnung 3.0 promoted BOTH to
     * MANDATORY 1..1 fields (BR-DE, effective 2024-02-01), so the writer must never omit them — a missing
     * EndpointID makes the KoSIT validator reject the document. Resolve down a most-correct-first chain:
     *
     *   1. an explicitly configured endpoint (a real delivery address) + its scheme (default "EM")
     *   2. an email → EAS "EM", a genuine routing address (what the standard actually intends)
     *   3. the VAT id → EAS "9930", an identifier pressed into service — validator-safe last resort
     *
     * Only a party with none of these yields a null endpoint; that is a configuration gap (the resulting
     * XML would be rejected), not something to invent a value for, so it degrades rather than fabricating.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: ?string, 1: string}
     */
    private static function resolveEndpoint(array $data, ?string $vatId): array
    {
        $explicit = self::nonEmptyString($data, 'endpoint_id');

        if ($explicit !== null) {
            return [$explicit, self::string($data, 'endpoint_scheme', self::EAS_EMAIL)];
        }

        $email = self::nonEmptyString($data, 'email');

        if ($email !== null) {
            return [$email, self::EAS_EMAIL];
        }

        if ($vatId !== null) {
            return [$vatId, self::EAS_GERMAN_VAT];
        }

        return [null, self::string($data, 'endpoint_scheme', self::EAS_EMAIL)];
    }

    /** @param array<array-key, mixed> $data */
    private static function nonEmptyString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
