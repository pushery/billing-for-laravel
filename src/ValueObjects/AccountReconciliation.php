<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * The month-end comparison between a sub-ledger and the collective account it stands behind.
 *
 * A difference here is a FAULT, not a note: the two are the same obligation counted twice, so they agree or
 * one of them is wrong. Which is why this carries the difference as a figure rather than a flag — a report
 * that says "does not reconcile" and nothing more sends somebody looking through a month of documents.
 *
 * Merchants in debt are listed apart from the total. Netting a negative balance into it hides two errors at
 * once: the shortfall itself, and whichever payable happens to cancel it out.
 */
final readonly class AccountReconciliation
{
    /**
     * @param  array<string, Money>  $balances  per merchant, keyed by the morph pair
     */
    public function __construct(
        public array $balances,
        public Money $subLedgerTotal,
        public Money $collectiveAccountBalance,
    ) {}

    /** What the two disagree by. Zero is the only acceptable value. */
    public function difference(): Money
    {
        return $this->subLedgerTotal->minus($this->collectiveAccountBalance);
    }

    public function isBalanced(): bool
    {
        return $this->difference()->isZero();
    }

    /**
     * The merchants who owe the platform rather than the other way round.
     *
     * They are real and they are collectible, so they belong in the report — separately, because a negative
     * balance summed into a total is a shortfall that has been made to disappear by an unrelated payable.
     *
     * @return array<string, Money>
     */
    public function negativeBalances(): array
    {
        return array_filter($this->balances, static fn (Money $balance): bool => $balance->isNegative());
    }
}
