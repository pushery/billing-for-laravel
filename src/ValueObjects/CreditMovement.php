<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Proration\CreditBalanceProrationStrategy;

/**
 * One movement of an owner's credit balance, in the form the books need it.
 *
 * The balance is a multi-purpose voucher in law: money held against a promise, with neither
 * the place of supply nor the rate known when it is taken. So a paid top-up is NOT revenue — it is a
 * liability — and the supply happens later, when the balance pays for something.
 *
 * AND THAT IS WHERE THE VOUCHER ANALOGY STOPS. A voucher redemption IS the sale, so it books against
 * revenue. A credit redemption pays an invoice that already exists — `OrderInvoiceIssuer` raised it on the
 * local lane, `PersistInvoice` persisted the provider's on the other — and that invoice has already booked
 * revenue against a receivable. Booking the redemption against revenue a second time would count the
 * turnover twice. It settles the RECEIVABLE.
 *
 * The reason travels with the movement rather than being reduced to a sign here: a debit that spends credit
 * and a debit that takes it back are the same number and different books.
 */
final readonly class CreditMovement
{
    public function __construct(
        public CreditReason $reason,
        public Money $amount,
        public string $reference,
        public CarbonInterface $occurredOn,
        public ?Money $unpaidShare = null,
    ) {}

    /**
     * How much of a SPEND came out of credits nobody paid for — null where the question does not arise.
     *
     * A balance is fungible and a spend is one entry against the mixed total, so the two halves have to be
     * separated before either can be booked: a paid top-up is money held against a promise, a proration
     * credit is consideration being given back. {@see CreditConsumption} derives the split by replaying the
     * append-only log, which is why this arrives already computed rather than being asked here — the answer
     * needs the owner's whole history, and this object deliberately holds no Eloquent model.
     *
     * Null is read as none, and that is safe only because the batch computes the split for EVERY spend it
     * exports. A movement built anywhere else describes a grant or a reversal, where there is nothing to
     * split — the question does not arise rather than being answered with a zero.
     */
    public function unpaidShareMinor(): int
    {
        // `->` rather than `?->`, because `??` already suppresses the access on null and static analysis
        // rejects the nullsafe as redundant here. It reads like a bug and is not one.
        return $this->unpaidShare->minorUnits ?? 0;
    }

    /**
     * What of this movement the books can state — the movement itself, less the share nobody paid for.
     *
     * Signed like the movement, so an offset of 12.000 that took 4.000 out of a proration credit books
     * −8.000 and not 8.000. The direction is carried by the accounts a row names, and handing a magnitude
     * around is how a booking ends up on the correct accounts in the wrong direction.
     */
    public function bookedAmount(): Money
    {
        return Money::of($this->amount->minorUnits + $this->unpaidShareMinor(), $this->amount->currency);
    }

    /**
     * Whether this movement produces a booking row at all.
     *
     * IT LIVES HERE BECAUSE TWO PLACES ASK IT — the export, to decide whether to emit, and the period
     * batch, to count what it emitted. Held in both, they would eventually disagree, and the disagreement
     * would surface as a confirmation line stating a number of bookings the file does not contain.
     *
     * The `null` arm is not a formality: a movement that was never split carries null rather than zero, and
     * a zero-amount top-up still gets its row — the ledger writes an entry for a movement of nothing on
     * purpose, and the books follow it. Only an offset whose entire amount came out of a proration credit
     * books nothing, because there is no liability to release and no money that changed hands.
     */
    public function booksARow(): bool
    {
        return $this->booksAgainstMoney()
            && (! $this->unpaidShare instanceof Money || $this->bookedAmount()->minorUnits !== 0);
    }

    /**
     * Whether this movement has money or an invoice behind it — the ones the books can state today.
     *
     * Four of the six reasons do. {@see CreditReason::ProrationCredit} does not and is the reason this
     * method exists rather than a `match` at the call site: unused time on a swapped plan becomes spendable
     * balance with **no payment and no credit note** ({@see CreditBalanceProrationStrategy}),
     * so booking it as a liability would create one against nothing. Leaving it out is not an omission —
     * what it IS in the books (a reduction of the original taxable base under § 17 UStG, or something else)
     * is an open question, and the monthly batch reports the amount instead of inventing an answer.
     */
    public function booksAgainstMoney(): bool
    {
        return $this->reason->booksAgainstMoney();
    }
}
