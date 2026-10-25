<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\InsufficientInternalBalance;
use Pushery\Billing\ValueObjects\Money;

/**
 * A balance the platform keeps for its users, from which a marketplace sale can be paid without a provider.
 *
 * The package keeps no such balance. A host that holds money for its users, the earnings of its creators for
 * one, implements this contract over its own ledger, and a sale paid from it is recorded like any other: the
 * same charge, the same commission, the same documents and the same tax. Only the payment differs. The buyer's
 * balance is debited where a card would be charged, and the merchant's share is credited where a provider
 * transfer would move it.
 *
 * The debit runs in the database transaction that records the sale, so a ledger kept in the same database is
 * never debited for a sale that was not recorded. The credit runs when the share moves, which is at the sale
 * unless the merchant's payouts are withheld or the buyer protection holds the share, and then on its release.
 *
 * Both calls are idempotent by their key: a second call with a key already used makes no second entry and
 * returns the reference of the first. A retried sale and a retried credit depend on that.
 */
interface InternalBalance
{
    /** The provider a charge paid from this balance is recorded under. */
    public const string PROVIDER = 'internal_balance';

    /**
     * Take the amount off the owner's balance, and return the reference of the entry.
     *
     * @throws InsufficientInternalBalance where the balance does not cover the amount, and then nothing is taken
     */
    public function debit(Model $owner, Money $amount, string $idempotencyKey): string;

    /** Put the amount on the owner's balance, and return the reference of the entry. */
    public function credit(Model $owner, Money $amount, string $idempotencyKey): string;
}
