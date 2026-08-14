<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * What a provider actually withheld on one routed subscription invoice, and from whom.
 *
 * ## Why this is READ rather than computed
 *
 * A routed subscription is priced with a RATE, not an amount: the lane sets `application_fee_percent`, and
 * the provider applies it to each invoice as it is raised. So the absolute commission is not knowable when
 * the subscription is created — it exists once per cycle, on the invoice, and only the provider has it.
 *
 * The package could compute it instead, and that was the road not taken. Two derivations of one fact agree
 * until one of them changes: a rate edited between the second cycle and the third, a proration line, a
 * rounding rule. When they part, the ledger holds a **plausible wrong number** — and the ledger is what the
 * reversal caps, the earnings counter and the small-business judgement all read. A figure that is merely
 * plausible is the worst possible content for it, because nothing goes red.
 *
 * ## The terms travel WITH the amounts, and that is not redundancy
 *
 * A full clawback needs only the amounts. A PARTIAL one needs the terms: a proportional share is the wrong
 * figure the moment a fee has a fixed part, and the only other source would be today's configuration — a
 * platform that raised its rate would then claw old cycles back at the new one, with nothing on the row to
 * say so. So the rate is carried here and frozen onto the row beside the money.
 */
final readonly class RoutedInvoiceCommission
{
    public function __construct(
        /** The connected account the money was routed to, as the provider names it. */
        public string $merchantAccountReference,
        /** What the buyer paid on this invoice. */
        public Money $gross,
        /**
         * What the provider actually withheld — its number, not ours.
         *
         * This is the whole point of reading rather than computing. A refused or partly applied fee reports
         * what really happened; a computed one reports what should have.
         */
        public Money $fee,
        /**
         * The rate the commission was taken at, in basis points, as it stood for THIS cycle.
         *
         * Read from the subscription rather than from configuration, so a rate changed later cannot rewrite
         * a cycle already billed. Null only when the provider does not state one, which on this lane means
         * the terms could not be established — and a caller must treat that as unknown rather than as zero.
         */
        public ?int $feeBps,
    ) {}

    /** What is left for the merchant. Derived, because net is what remains by definition. */
    public function net(): Money
    {
        return $this->gross->minus($this->fee);
    }
}
