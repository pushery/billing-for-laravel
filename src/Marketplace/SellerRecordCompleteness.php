<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Enums\SellerFieldBasis;
use Pushery\Billing\Enums\SellerRecordCompleteness as State;
use Pushery\Billing\ValueObjects\SellerRecordField;

/**
 * Whether a seller's record is good enough to act on — and, crucially, what "good enough" is allowed to
 * mean.
 *
 * Only a missing REQUIRED field makes a record incomplete. A record missing precautionary fields is
 * complete, because an escalation that withheld somebody's money over data no law entitles anybody to
 * demand would be a worse failure than the gap it was chasing.
 *
 * Format is checked here rather than at filing time. An identifier that fails its own check digit is
 * wrong the moment it is typed; discovering that in January, against a deadline, means going back to a
 * seller who has moved on — the same chase the up-front collection exists to avoid.
 */
final readonly class SellerRecordCompleteness
{
    public function __construct(private ReportingProfile $profile) {}

    /**
     * @param  array<string, mixed>  $values  what the seller has supplied, by field name
     */
    public function evaluate(
        array $values,
        bool $isLegalEntity,
        bool $reportable,
        ?CarbonImmutable $validUntil = null,
        ?CarbonImmutable $now = null,
    ): State {
        foreach ($this->profile->fieldsFor($isLegalEntity, $reportable) as $field) {
            if ($field->basis !== SellerFieldBasis::Required) {
                continue;
            }

            if (! $this->satisfied($field, $values[$field->name] ?? null)) {
                return State::Incomplete;
            }
        }

        // Complete once, and since gone stale. Ordered after the missing-field check on purpose: "you never
        // gave us this" and "what you gave us is out of date" are different messages to send somebody, and
        // the first is the more urgent of the two.
        if ($validUntil instanceof CarbonImmutable && $validUntil->lessThanOrEqualTo($now ?? CarbonImmutable::now())) {
            return State::Expired;
        }

        return State::Complete;
    }

    /**
     * Which required fields are still missing — what a request to the seller should actually ask for.
     *
     * Naming them matters: "your record is incomplete" sends somebody hunting through a form, and the one
     * thing that makes them answer is being told exactly what is wanted.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function missingRequired(array $values, bool $isLegalEntity, bool $reportable): array
    {
        $missing = [];

        foreach ($this->profile->fieldsFor($isLegalEntity, $reportable) as $field) {
            if ($field->basis === SellerFieldBasis::Required && ! $this->satisfied($field, $values[$field->name] ?? null)) {
                $missing[] = $field->name;
            }
        }

        return $missing;
    }

    private function satisfied(SellerRecordField $field, mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return match ($field->name) {
            'payout_account' => self::checksumHolds($value),
            'seller_tax_identifier' => self::taxIdentifierHolds($value),
            default => true,
        };
    }

    /**
     * The account number's own check, so a transposed digit is caught where it is typed.
     *
     * A wrong account number is not merely a failed payout: it can be somebody else's valid account, in
     * which case the money arrives and the mistake is discovered by whoever did not get paid.
     */
    public static function checksumHolds(string $account): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $account) ?? '');

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $normalized) !== 1) {
            return false;
        }

        $rotated = substr($normalized, 4).substr($normalized, 0, 4);
        $numeric = '';

        foreach (str_split($rotated) as $character) {
            $numeric .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        return bcmod($numeric, '97') === '1';
    }

    /**
     * The identifier's own check digit.
     *
     * Eleven digits, the last of which is derived from the other ten, with no digit appearing more than
     * three times and at least one repeating — the properties that make a typo detectable rather than
     * merely unlikely.
     */
    public static function taxIdentifierHolds(string $identifier): bool
    {
        $digits = preg_replace('/\s+/', '', $identifier) ?? '';

        if (preg_match('/^\d{11}$/', $digits) !== 1) {
            return false;
        }

        $product = 10;

        foreach (str_split(substr($digits, 0, 10)) as $digit) {
            $sum = ((int) $digit + $product) % 10;
            $sum = $sum === 0 ? 10 : $sum;
            $product = (2 * $sum) % 11;
        }

        $check = (11 - $product) % 10;

        return $check === (int) $digits[10];
    }
}
