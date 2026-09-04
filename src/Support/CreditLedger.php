<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Models\CreditBalance;
use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;

/**
 * Reads and adjusts an owner's credit balance. credit() applies a signed amount (a negative amount
 * spends the balance down) atomically under a row lock, so concurrent proration credits and offsets
 * never race. The balance is scoped per currency.
 *
 * Every movement writes a {@see CreditLedgerEntry} in the SAME transaction as the balance it moves, so the
 * running total can always be taken apart into what produced it. That is why the reason is a required
 * argument rather than an optional one: an unexplained movement is the state this ledger used to be able to
 * reach, and a default would let it reach it again while looking deliberate. Before the entries existed the
 * "why" lived only in the audit log, written by a separate call after the balance transaction had already
 * committed — so an interruption in between left a balance nobody could account for, and `billing:prune`
 * ages audit rows out on a clock while a balance is a holding and is never pruned.
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
    public function debit(Model $owner, Money $amount, CreditReason $reason, ?CreditSource $source = null): Money
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A debit amount must be positive.');
        }

        return $this->credit($owner, $amount->negated(), $reason, $source);
    }

    /**
     * Debit at most $ceiling of an owner's balance, and answer what ACTUALLY moved.
     *
     * The read, the cap and the debit are one movement under the balance row's own lock, and that is the
     * whole difference between this and balanceFor() followed by debit(). Between those two the balance can
     * change — and a caller that decides a figure, goes off to a provider, and then debits what it decided
     * will spend a balance that is no longer there.
     *
     * That drives the balance NEGATIVE, and negative is a one-way street here: no cycle ever collects it
     * back, because every later offset reads a non-positive figure and skips itself. Two entries that each
     * read like a correct offset, and the seller is permanently out the smaller of them.
     *
     * A zero ceiling or an empty balance moves nothing and writes no entry — this answers "how much of it
     * could you use", and "none" is an answer, not a movement.
     */
    public function spendUpTo(Model $owner, Money $ceiling, CreditReason $reason, ?CreditSource $source = null): Money
    {
        if (! $ceiling->isPositive()) {
            return Money::of(0, $ceiling->currency);
        }

        return DB::transaction(function () use ($owner, $ceiling, $reason, $source): Money {
            $available = $this->lockedBalance($owner, $ceiling->currency)->balance_minor;

            $spend = min($available, $ceiling->minorUnits);

            if ($spend <= 0) {
                return Money::of(0, $ceiling->currency);
            }

            $this->credit($owner, Money::of(-$spend, $ceiling->currency), $reason, $source);

            return Money::of($spend, $ceiling->currency);
        });
    }

    /**
     * @param  Money  $amount  Signed: a negative amount spends the balance down.
     * @param  CreditReason  $reason  Why the balance moved. Required — see the class docblock.
     * @param  ?CreditSource  $source  What caused it (an add-on purchase, a refund attempt), when the caller knows.
     *                                 A dedicated type, not a second model — see CreditSource for why.
     */
    public function credit(Model $owner, Money $amount, CreditReason $reason, ?CreditSource $source = null): Money
    {
        return DB::transaction(function () use ($owner, $amount, $reason, $source): Money {
            $balance = $this->lockedBalance($owner, $amount->currency);

            $updated = $balance->balance_minor + $amount->minorUnits;
            $balance->update(['balance_minor' => $updated]);

            // Inside the lock, so the entry and the total it produced commit together or not at all. A
            // zero movement still gets an entry: "nothing moved, and here is why we looked" is an answer,
            // and dropping it would make the entries agree with the balance only by coincidence.
            CreditLedgerEntry::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'reason' => $reason,
                'source_type' => $source?->type,
                'source_id' => $source?->id,
                'created_at' => Carbon::now(),
            ]);

            return Money::of($updated, $amount->currency);
        });
    }

    /**
     * The owner's balance row for this currency, created if absent and held under a row lock.
     *
     * Both writers go through here so the lock is never accidentally skipped by one of them: a movement
     * that reads without the lock is indistinguishable from one that reads with it until two of them
     * overlap, and by then the balance is already wrong.
     */
    private function lockedBalance(Model $owner, string $currency): CreditBalance
    {
        CreditBalance::query()->insertOrIgnore([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'currency' => $currency,
            'balance_minor' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return CreditBalance::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
