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

    /**
     * EAS codes for a VAT identification number used as an electronic address, by the country prefix of the number.
     *
     * The schemes the EAS code list gives a country's VAT number (horstoeko/zugferd's ZugferdElectronicAddressScheme,
     * v1.0.132): the entries named as a country's VAT number, and Austria's Umsatzsteuer-Identifikationsnummer, Finland's
     * Value Add Tax Identifier, Italy's Partita IVA and Spain's tax agency, which Peppol labels ES:VAT. Greece issues its
     * numbers with the prefix EL. A number from a country without such a scheme, Denmark for one, is not stated under
     * another country's.
     */
    private const array EAS_VAT_BY_COUNTRY = [
        'AD' => '9922', 'AL' => '9923', 'AT' => '9914', 'BA' => '9924', 'BE' => '9925', 'BG' => '9926',
        'CH' => '9927', 'CY' => '9928', 'CZ' => '9929', 'DE' => '9930', 'EE' => '9931', 'EL' => '9933',
        'ES' => '9920', 'FI' => '0213', 'FR' => '9957', 'GB' => '9932', 'GR' => '9933', 'HR' => '9934',
        'HU' => '9910', 'IE' => '9935', 'IT' => '0211', 'LI' => '9936', 'LT' => '9937', 'LU' => '9938',
        'LV' => '9939', 'MC' => '9940', 'ME' => '9941', 'MK' => '9942', 'MT' => '9943', 'NL' => '9944',
        'PL' => '9945', 'PT' => '9946', 'RO' => '9947', 'RS' => '9948', 'SE' => '9955', 'SI' => '9949',
        'SK' => '9950', 'SM' => '9951', 'TR' => '9952', 'VA' => '9953',
    ];

    /**
     * @param  ?string  $contactName  the contact point (BT-41 for a seller), a department or a person
     * @param  ?string  $contactPhone  the contact's telephone number (BT-42)
     * @param  ?string  $contactEmail  the contact's email address (BT-43)
     * @param  ?string  $iban  the account a payment by credit transfer goes to (BT-84)
     * @param  ?string  $bic  the bank of that account (BT-86)
     */
    public function __construct(
        public string $name,
        public string $address,
        public string $postcode,
        public string $city,
        public string $country,
        public ?string $vatId,
        public ?string $endpointId,
        public string $endpointScheme,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
        public ?string $iban = null,
        public ?string $bic = null,
    ) {}

    /**
     * The party as a snapshot array that {@see fromArray} reads back unchanged.
     *
     * The resolved endpoint is stored explicitly (as `endpoint_id`, which fromArray takes verbatim), so a
     * party frozen onto a document reconstructs identically — the same electronic address it was issued
     * with, not one re-derived later from a changed rule.
     *
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'country' => $this->country,
            'vat_id' => $this->vatId,
            'endpoint_id' => $this->endpointId,
            'endpoint_scheme' => $this->endpointScheme,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail,
            'iban' => $this->iban,
            'bic' => $this->bic,
        ];
    }

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
            contactName: self::nonEmptyString($data, 'contact_name'),
            contactPhone: self::nonEmptyString($data, 'contact_phone'),
            contactEmail: self::nonEmptyString($data, 'contact_email'),
            iban: self::nonEmptyString($data, 'iban'),
            bic: self::nonEmptyString($data, 'bic'),
        );
    }

    /** Whether the party names a contact at all (BG-6 for a seller, BG-9 for a buyer). */
    public function hasContact(): bool
    {
        return $this->contactName !== null || $this->contactPhone !== null || $this->contactEmail !== null;
    }

    /**
     * Resolve the party's electronic address (BT-34 seller / BT-49 buyer). XRechnung 3.0 promoted BOTH to
     * MANDATORY 1..1 fields (BR-DE, effective 2024-02-01), so the writer must never omit them — a missing
     * EndpointID makes the KoSIT validator reject the document. Resolve down a most-correct-first chain:
     *
     *   1. an explicitly configured endpoint (a real delivery address) + its scheme (default "EM")
     *   2. an email → EAS "EM", a genuine routing address (what the standard actually intends)
     *   3. the VAT id → the EAS code of its country's VAT-number scheme, an identifier pressed into service. A number
     *      from a country the code list gives no such scheme yields none rather than another country's: a French
     *      number stated as a German one names a scheme that does not hold it
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

        $scheme = $vatId === null ? null : (self::EAS_VAT_BY_COUNTRY[strtoupper(substr($vatId, 0, 2))] ?? null);

        if ($vatId !== null && $scheme !== null) {
            return [$vatId, $scheme];
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
