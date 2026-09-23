<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Carbon\CarbonImmutable;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a dispute Stripe just opened says about itself, read off the dispute object.
 *
 * Both webhook mappers need it: a dispute on a charge the platform took arrives at the platform endpoint, one on
 * a merchant's own charge at the connected-account endpoint, and the dispute object is the same shape at either.
 * The mappers build `DisputeOpened` from it themselves, because what differs is where the dispute lives and whose
 * sale it was, and because a producer of an event with effects on it belongs where the lane guard can see it.
 */
final readonly class StripeDisputeOpening
{
    private function __construct(
        public string $dispute,
        /** The payment intent where there is one and the charge otherwise, as the outcome of the case names it. */
        public string $payment,
        public Money $amount,
        public DisputeReason $reason,
        public ?string $reasonCode,
        public ?CarbonImmutable $evidenceDueBy,
    ) {}

    /**
     * The opening, or null when the object lacks the dispute's id, a payment or a currency.
     *
     * @param  array<array-key, mixed>  $object
     */
    public static function from(array $object): ?self
    {
        $dispute = self::string($object, 'id');
        $payment = self::string($object, 'payment_intent') ?? self::string($object, 'charge');
        $currency = self::string($object, 'currency');

        if ($dispute === null || $payment === null || $currency === null) {
            return null;
        }

        $amount = $object['amount'] ?? null;
        $reason = self::string($object, 'reason');

        return new self(
            dispute: $dispute,
            payment: $payment,
            amount: Money::of(is_int($amount) ? $amount : 0, strtoupper($currency)),
            reason: DisputeReason::fromProvider($reason),
            reasonCode: $reason,
            evidenceDueBy: self::dueBy($object),
        );
    }

    /**
     * When Stripe stops accepting evidence, as `evidence_details.due_by` states it in Unix seconds.
     *
     * @param  array<array-key, mixed>  $object
     */
    private static function dueBy(array $object): ?CarbonImmutable
    {
        $details = $object['evidence_details'] ?? null;
        $dueBy = is_array($details) ? ($details['due_by'] ?? null) : null;

        return is_int($dueBy) ? CarbonImmutable::createFromTimestampUTC($dueBy) : null;
    }

    /** @param  array<array-key, mixed>  $object */
    private static function string(array $object, string $key): ?string
    {
        $value = $object[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
