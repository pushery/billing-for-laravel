<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\ValueObjects\Money;

/**
 * A chargeback was decided against a settled payment.
 *
 * The marketplace dimensions are appended and nullable, so a single-seller chargeback carries exactly what it
 * always carried. Where a sale was routed they say who received the money, which transfer moved it, and what
 * the provider charged for handling the dispute.
 *
 * The dispute fee is carried SEPARATELY rather than netted off the amount, and that is the point of it being
 * here at all. It is a service the provider supplied to the platform — its own inbound supply, taxed in the
 * platform's own country under a reverse charge in the common case — not a deduction from what the buyer
 * paid. Folded into the amount it would silently reduce the turnover being corrected, and the correction
 * would be wrong by the fee on every disputed sale while every document still added up.
 */
final readonly class ChargebackReceived implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $reference,
        public Money $amount,
        /** The merchant the money was routed to, where it was. Null for a single-seller sale. */
        public ?string $merchantReference = null,
        /**
         * The transfer that moved it, which is what a reversal acts on.
         *
         * ALWAYS NULL ON A DISPUTE, and that is the provider's shape rather than an omission here: a dispute
         * object carries no transfer field at all. The mapper used to read one, so this arrived null on every
         * real webhook while the code read as though it were populated — absent-always, not absent-sometimes.
         *
         * The reference is knowable, just not from a payload: the routed charge row holds it against the
         * charge reference this event already carries. Resolving it belongs to the path that applies the
         * reversal, where the ledger is in hand. It stays on the event because a caller constructing one
         * from a source that DOES know the transfer should be able to say so.
         */
        public ?string $transferReference = null,
        /** What the provider charged for handling the dispute — an inbound supply, never a deduction. */
        public ?Money $feeAmount = null,
        /** Why the money is going back. */
        public ?ReversalCause $cause = null,
        /**
         * Why the dispute was raised, which is a different question from `cause` and decides more.
         *
         * `cause` says the money is leaving because a dispute was lost. This says what the dispute was ABOUT,
         * and that is what determines whether the merchant's own settlement document is corrected as well —
         * a buyer who received nothing has a claim against the supply, while a stolen card is a loss the
         * platform carries over a supply that actually happened.
         *
         * Nullable and trailing, so a single-seller chargeback carries exactly what it always carried.
         */
        public ?DisputeReason $reason = null,
        /**
         * The provider's own reference for the DISPUTE, as distinct from the charge it was raised against.
         *
         * They are not interchangeable, and treating them as one cost the second fee. A charge can carry more
         * than one dispute — the provider's SDK says so in as many words, because only part of an order may be
         * disputed — so a fee claimed on the charge reference finds the first dispute's row and writes
         * nothing for the second. That is real money the platform paid, missing as an expense and as its
         * reverse-charge position.
         *
         * Everything else on this event is deliberately about the CHARGE: the correcting documents and the
         * clawback both act on the sale, not on the dispute, and for them the charge reference is right.
         *
         * Nullable and trailing, so a caller that dispatches this event by hand keeps working and simply gets
         * the coarser claim it always had.
         */
        public ?string $disputeReference = null,
    ) {}
}
