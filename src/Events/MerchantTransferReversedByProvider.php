<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

/**
 * The PROVIDER reversed a transfer this platform did not ask it to reverse.
 *
 * ## Why this event has to exist
 *
 * `transfer_reversed_minor` had one writer, reachable only through the admin refund and the chargeback job
 * — both platform-initiated. A reversal Stripe performs itself, from the dashboard or automatically, had no
 * entry point at all, and the balance reader subtracts exactly that column. So the creator went on being
 * shown money the provider had already taken back, and a payout on that figure came out of a pot that no
 * longer existed.
 *
 * ## Cumulative, not incremental, and that is the whole idempotency story
 *
 * `amountReversedMinor` is what the provider says has been reversed IN TOTAL for this transfer, which is
 * what the event carries. Recorded as an absolute figure, a redelivery — the ordinary case, not the exotic
 * one — writes the same number twice and changes nothing, while a second, larger reversal is followed
 * correctly. An incremental reading would need a dedup table to be safe and would still be wrong for the
 * partial-then-full case.
 *
 * ## It records. It does not adjudicate.
 *
 * Which ledger is authoritative when the provider and this package disagree is a different question, and
 * deliberately not answered here — see the seventh acceptance line of the reconciliation ticket. This event
 * states what the provider reported, and nothing more.
 */
final readonly class MerchantTransferReversedByProvider implements BillingDomainEvent
{
    public function __construct(
        public string $provider,
        /** The provider's id for the transfer, which is what attributes it to a recorded sale. */
        public string $transferReference,
        /** The provider's CUMULATIVE reversed amount for that transfer, in minor units. */
        public int $amountReversedMinor,
    ) {}
}
