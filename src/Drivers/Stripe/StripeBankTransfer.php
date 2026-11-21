<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use InvalidArgumentException;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentAddress;
use Pushery\Billing\ValueObjects\PaymentInstructions;

/**
 * A bank transfer at Stripe: the customer's balance, funded by a transfer to an account Stripe opens for the customer.
 *
 * A payment intent waiting for the money is `requires_action`, and its next action, `display_bank_transfer_instructions`,
 * names the reference and the accounts the customer can pay to; the one with an IBAN becomes the instructions. Stripe
 * takes a transfer in five currencies, each to an account of its own kind, and one in euros to an IBAN of one of six
 * countries.
 */
final readonly class StripeBankTransfer
{
    /** Stripe's id of the method a bank transfer pays with. */
    public const string METHOD = 'customer_balance';

    /** The countries Stripe opens an IBAN in, for a transfer in euros. */
    public const array IBAN_COUNTRIES = ['BE', 'DE', 'ES', 'FR', 'IE', 'NL'];

    /** The kind of account Stripe opens for a transfer in each currency it takes one in. */
    private const array TYPES = ['EUR' => 'eu_bank_transfer', 'GBP' => 'gb_bank_transfer', 'USD' => 'us_bank_transfer', 'JPY' => 'jp_bank_transfer', 'MXN' => 'mx_bank_transfer'];

    /**
     * What a payment intent asks the customer to transfer while it waits for the money, or null for any other intent
     * and for a transfer to an account without an IBAN.
     *
     * @param  array<array-key, mixed>  $intent
     */
    public static function instructions(array $intent): ?PaymentInstructions
    {
        $next = is_array($intent['next_action'] ?? null) ? $intent['next_action'] : [];
        $display = ($next['type'] ?? null) === 'display_bank_transfer_instructions' && is_array($next['display_bank_transfer_instructions'] ?? null)
            ? $next['display_bank_transfer_instructions']
            : [];
        $reference = $display['reference'] ?? null;
        $addresses = is_array($display['financial_addresses'] ?? null) ? $display['financial_addresses'] : [];

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        foreach ($addresses as $address) {
            $account = is_array($address) && is_array($address['iban'] ?? null) ? $address['iban'] : [];
            $iban = $account['iban'] ?? null;

            if (is_string($iban) && $iban !== '') {
                $bic = $account['bic'] ?? null;
                $holder = $account['account_holder_name'] ?? null;

                return new PaymentInstructions(
                    $reference,
                    $iban,
                    is_string($bic) && $bic !== '' ? $bic : null,
                    is_string($holder) && $holder !== '' ? $holder : null,
                );
            }
        }

        return null;
    }

    /**
     * The method the customer pays an intent with: the type of its latest charge's payment method where the intent was
     * read with that charge, the bank transfer while one waits for the money, and otherwise the one method the intent
     * allows, or null where it allows several.
     *
     * @param  array<array-key, mixed>  $intent
     */
    public static function methodOf(array $intent): ?string
    {
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $details = is_array($charge['payment_method_details'] ?? null) ? $charge['payment_method_details'] : [];
        $type = $details['type'] ?? null;

        if (is_string($type) && $type !== '') {
            return $type;
        }

        $next = is_array($intent['next_action'] ?? null) ? $intent['next_action'] : [];

        if (($next['type'] ?? null) === 'display_bank_transfer_instructions') {
            return self::METHOD;
        }

        $types = is_array($intent['payment_method_types'] ?? null) ? $intent['payment_method_types'] : [];
        $only = count($types) === 1 ? reset($types) : null;

        return is_string($only) && $only !== '' ? $only : null;
    }

    /**
     * The options of a Checkout Session that offers a bank transfer: the kind of account Stripe opens for the currency,
     * and for euros the country of the IBAN, the billing address's where Stripe opens one there, else the one
     * configured.
     *
     * @param  mixed  $configured  `billing.stripe.bank_transfer_country`, as the configuration holds it
     * @return array{funding_type: string, bank_transfer: array<string, mixed>}
     *
     * @throws InvalidArgumentException for a currency Stripe takes no transfer in, or a transfer in euros with no
     *                                  country Stripe opens an IBAN in
     */
    public static function checkoutOptions(Money $amount, ?PaymentAddress $billing, mixed $configured): array
    {
        $type = self::TYPES[$amount->currency] ?? null;

        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                'Stripe takes a bank transfer in %s, and the charge is in %s.',
                implode(', ', array_keys(self::TYPES)),
                $amount->currency,
            ));
        }

        if ($type !== self::TYPES['EUR']) {
            return ['funding_type' => 'bank_transfer', 'bank_transfer' => ['type' => $type]];
        }

        $country = strtoupper((string) $billing?->country);

        if (! in_array($country, self::IBAN_COUNTRIES, true)) {
            $country = is_string($configured) ? strtoupper($configured) : '';
        }

        if (! in_array($country, self::IBAN_COUNTRIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'A bank transfer in euros at Stripe goes to an IBAN of %s: the country of the billing address where it is one of them, otherwise `billing.stripe.bank_transfer_country`, and the charge has neither.',
                implode(', ', self::IBAN_COUNTRIES),
            ));
        }

        return ['funding_type' => 'bank_transfer', 'bank_transfer' => ['type' => $type, 'eu_bank_transfer' => ['country' => $country]]];
    }
}
