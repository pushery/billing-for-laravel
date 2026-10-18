<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * An account number a SEPA direct debit can be drawn from.
 *
 * Checked before a mandate is registered, in the order people make mistakes: a country whose accounts a direct
 * debit reaches, the length that country's numbers have, and the check digits (ISO 13616, computed with
 * ISO 7064 MOD 97-10). A mistyped number the provider accepted would become a mandate on an account nobody
 * meant, and its first debit would come back refused weeks later.
 *
 * Spaces and lower case are how people write an IBAN and are dropped. Any other character that is not a letter
 * or a digit makes the input something other than an IBAN.
 */
final readonly class Iban
{
    /**
     * The countries Mollie collects SEPA direct debits from, each with the length of its IBAN.
     *
     * A number from another country is refused by its country, even where its check digits hold.
     *
     * @var array<string, int>
     */
    private const array LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18,
        'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MT' => 31,
        'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'VA' => 22,
    ];

    private function __construct(public string $value) {}

    /** The IBAN as typed, in its electronic form, or null when it cannot be one a direct debit is drawn from. */
    public static function tryFrom(string $input): ?self
    {
        $value = strtoupper((string) preg_replace('/\s+/u', '', $input));

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $value) !== 1) {
            return null;
        }

        $length = self::LENGTHS[substr($value, 0, 2)] ?? null;

        if ($length === null || strlen($value) !== $length || ! self::checkDigitsHold($value)) {
            return null;
        }

        return new self($value);
    }

    /** The country the account is held in. */
    public function country(): string
    {
        return substr($this->value, 0, 2);
    }

    /** The last four characters, which is as much of the number as the consent record keeps. */
    public function lastFour(): string
    {
        return substr($this->value, -4);
    }

    /**
     * ISO 7064 MOD 97-10: the country and the check digits move to the end, every letter becomes two digits
     * (A is 10, Z is 35), and the number read that way leaves 1 when divided by 97. Folded digit by digit,
     * because the number has more digits than an integer holds.
     */
    private static function checkDigitsHold(string $value): bool
    {
        $remainder = 0;

        foreach (str_split(substr($value, 4).substr($value, 0, 4)) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
