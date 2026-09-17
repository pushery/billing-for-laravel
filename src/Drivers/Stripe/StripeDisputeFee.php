<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\ValueObjects\Money;

/**
 * What the provider charged for handling a dispute, read off the dispute object.
 *
 * It lives in its own class because BOTH webhook mappers need the answer and they are different classes: a
 * dispute on a merchant's own charge arrives at the connected-account endpoint, a dispute on a charge the
 * platform itself took arrives at the platform one, and the fee is the same fact either way. Read twice it
 * would be parsed twice, and the parse below is one this package has already got wrong once.
 *
 * THE FIELD IS PLURAL AND IT IS A LIST. The mapper read `balance_transaction` — singular — and a Dispute has
 * no such key; it declares `balance_transactions`, "a list of zero, one, or two balance transactions that
 * show funds withdrawn and reinstated". So the fee was null on every real webhook, `RecordProviderFee`
 * dropped it at its own guard, and no provider-fee row was ever written for a lost dispute. The suite stayed
 * green because the fixtures wrote the singular key the code read — a payload a test invents can only ever
 * confirm the parse it was written against. StripeDisputePayloadShapeTest checks the keys against the SDK's
 * own Dispute declaration instead.
 *
 * SUMMED, not first-of-list, and that is what makes the two-entry case right. The withdrawal states the fee
 * as a positive number; a later reinstatement states it back as a negative one. Summing nets those to
 * nothing, which is the truth — taking the magnitude of each and adding them would report a fee that was
 * charged and refunded as charged twice.
 *
 * Read as a MAGNITUDE only at the end: the provider states a fee as a positive number on a negative
 * transaction, and a sign slipping through here would later be added where it should be subtracted.
 *
 * Absent rather than zero when no entry carries one — zero is a claim that nothing was charged, and this
 * cannot know that. A fee that nets to zero IS zero, and is reported as such.
 */
final readonly class StripeDisputeFee
{
    /**
     * @param  array<array-key, mixed>  $object
     */
    public static function from(array $object, string $currency): ?Money
    {
        $transactions = $object['balance_transactions'] ?? null;

        if (! is_array($transactions)) {
            return null;
        }

        $total = null;

        foreach ($transactions as $transaction) {
            $fee = is_array($transaction) ? ($transaction['fee'] ?? null) : null;

            if (is_int($fee)) {
                $total = ($total ?? 0) + $fee;
            }
        }

        return $total === null ? null : Money::of(abs($total), $currency);
    }
}
