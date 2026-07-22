<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Models\CreditBalance;
use Pushery\Billing\ValueObjects\Money;

/**
 * Reads and adjusts an owner's credit balance. credit() applies a signed amount (a negative amount
 * spends the balance down) atomically under a row lock, so concurrent proration credits and offsets
 * never race. The balance is scoped per currency.
 *
 * The balance is deliberately allowed to go NEGATIVE — a customer who is refunded credit they already
 * spent owes it back, and clamping at zero would silently forgive that debt. Both directions are just a
 * signed integer under the same lock.
 *
 * DO NOT ADD A PAYOUT OR TRANSFER METHOD HERE. Two properties of this class are load-bearing and hold only
 * because the corresponding code does not exist:
 *
 *  1. There is no path that pays a balance back OUT — credit is spent against what this package bills, and
 *     nothing else. The balance is a claim on future invoices, not a store of withdrawable value.
 *  2. Every method takes exactly ONE owner, so a balance cannot move between owners.
 *
 * Together those keep this a prepayment against the seller's own supplies. A withdraw()/payout() method, or
 * a debit() wired to a "pay out my remaining balance" button, changes what the instrument IS — and it does
 * so without breaking a single test, which is precisely why the properties are pinned by an explicit
 * containment test (tests/Unit/CreditLedgerContainmentTest.php) instead of being left to this comment. If a
 * feature seems to need one of these, it is a design decision to escalate, not a method to add.
 */
final class CreditLedger
{
    public function balanceFor(Model $owner, string $currency): Money
    {
        $balance = CreditBalance::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->value('balance_minor');

        return Money::of(is_int($balance) ? $balance : 0, $currency);
    }

    /**
     * Spend an owner's balance down by a positive amount (the reverse of a credit). A refund or a
     * clawed-back add-on debits what it granted; the balance may go negative if the customer already
     * spent it. Rejects a non-positive amount so a caller cannot smuggle a credit through debit().
     */
    public function debit(Model $owner, Money $amount): Money
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A debit amount must be positive.');
        }

        return $this->credit($owner, $amount->negated());
    }

    public function credit(Model $owner, Money $amount): Money
    {
        return DB::transaction(function () use ($owner, $amount): Money {
            CreditBalance::query()->insertOrIgnore([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'currency' => $amount->currency,
                'balance_minor' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = CreditBalance::query()
                ->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())
                ->where('currency', $amount->currency)
                ->lockForUpdate()
                ->firstOrFail();

            $updated = $balance->balance_minor + $amount->minorUnits;
            $balance->update(['balance_minor' => $updated]);

            return Money::of($updated, $amount->currency);
        });
    }
}
