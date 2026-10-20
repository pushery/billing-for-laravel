<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

/**
 * The names a payment method is shown by: a card brand as its scheme writes it, and a method without a brand by
 * the name of its kind.
 *
 * Providers hand both over as keys. Stripe writes a brand in lower case (`visa`, `amex`, `cartes_bancaires`) and
 * a kind by its API name (`sepa_debit`); Mollie writes a brand capitalized. A key this list does not know is
 * shown with its underscores as spaces and each word capitalized, never as the key itself.
 */
final class PaymentMethodNames
{
    /** @var array<string, string> */
    private const array BRANDS = [
        'amex' => 'American Express',
        'american_express' => 'American Express',
        'cartes_bancaires' => 'Cartes Bancaires',
        'diners' => 'Diners Club',
        'discover' => 'Discover',
        'eftpos_au' => 'eftpos',
        'jcb' => 'JCB',
        'maestro' => 'Maestro',
        'mastercard' => 'Mastercard',
        'unionpay' => 'UnionPay',
        'visa' => 'Visa',
    ];

    /** @var array<string, string> */
    private const array KINDS = [
        'au_becs_debit' => 'BECS Direct Debit',
        'bacs_debit' => 'Bacs Direct Debit',
        'bancontact' => 'Bancontact',
        'card' => 'Card',
        'creditcard' => 'Card',
        'directdebit' => 'SEPA Direct Debit',
        'ideal' => 'iDEAL',
        'link' => 'Link',
        'paypal' => 'PayPal',
        'sepa_debit' => 'SEPA Direct Debit',
        'us_bank_account' => 'US bank account',
    ];

    /** A card brand as its scheme writes it, `visa` and `Visa` alike as "Visa". */
    public static function brand(string $brand): string
    {
        return self::BRANDS[strtolower($brand)] ?? self::readable($brand);
    }

    /** A kind of payment method by its name, `sepa_debit` as "SEPA Direct Debit". */
    public static function kind(string $kind): string
    {
        return self::KINDS[strtolower($kind)] ?? self::readable($kind);
    }

    private static function readable(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}
