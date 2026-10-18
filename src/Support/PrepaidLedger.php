<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Models\PrepaidUnits;

/**
 * The prepaid-unit balance: units an owner bought for a meter, on top of the tier's per-cycle `included`
 * allowance. They are consumed only after `included` is exhausted, and they never expire — the owner paid
 * for them, so they roll across cycles (the owner's rule: `included` expires, prepaid does not).
 *
 * Consumption itself is not here: it happens inside UsageMeter's reserve/settle, under the same row lock
 * that defends the ceiling, because a unit that two concurrent requests both spend is a unit sold twice.
 * This class owns only the balance's ends — granting it and clawing it back — plus the read the gate and
 * the recorder need.
 *
 * Clawback is the unused-balance model: a refund takes back the units the owner has not consumed yet, up
 * to what the refund covers. Units already spent are not reclaimed — the value was delivered, and billing
 * them again after refunding the purchase would charge twice for one thing.
 *
 * The row also carries `debt`, the overdraft a blocking meter's grace let the owner run into. It is an advance on
 * the next units, so every grant pays it off before anything reaches the balance.
 */
final readonly class PrepaidLedger
{
    /** The owner's remaining prepaid units for a meter. */
    public function balance(Model $owner, string $meterKey): int
    {
        $balance = PrepaidUnits::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->value('balance');

        return is_int($balance) ? $balance : 0;
    }

    /**
     * Grant units. Under the row lock, so two concurrent grants cannot lose one another.
     *
     * The units first pay off the overdraft a meter's grace let the owner run into, and only the rest reaches the
     * balance: the grace was an advance on the next units, and a balance that ignored it would hand them out twice.
     * `granted_total` counts everything that was bought.
     */
    public function grant(Model $owner, string $meterKey, int $units): int
    {
        if ($units <= 0) {
            return $this->balance($owner, $meterKey);
        }

        return DB::transaction(function () use ($owner, $meterKey, $units): int {
            $row = $this->locked($owner->getMorphClass(), $owner->getKey(), $meterKey);
            $repaid = min($units, max(0, $row->debt));

            $row->update([
                'balance' => $row->balance + $units - $repaid,
                'granted_total' => $row->granted_total + $units,
                'debt' => $row->debt - $repaid,
            ]);

            return $row->balance;
        }, LockedRow::ATTEMPTS);
    }

    /**
     * Pay off as much of the owner's overdraft on a meter as `$units` covers, and answer how much that was.
     *
     * For a grant that is not a purchase, such as an allowance that expires: it pays the overdraft first as well,
     * and keeps only the rest. Runs in the caller's transaction, under this row's lock.
     */
    public function repayDebt(Model $owner, string $meterKey, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($owner, $meterKey, $units): int {
            $row = $this->locked($owner->getMorphClass(), $owner->getKey(), $meterKey);
            $repaid = min($units, max(0, $row->debt));

            if ($repaid > 0) {
                $row->update(['debt' => $row->debt - $repaid]);
            }

            return $repaid;
        }, LockedRow::ATTEMPTS);
    }

    /**
     * Claw back up to $units of unconsumed prepaid units, returning how many were actually taken back.
     *
     * Capped at the remaining balance: a refund cannot un-spend what the owner already used. That cap is
     * the whole policy — it is why a customer who bought 1 000 units, used 300 and was refunded keeps the
     * 300 they consumed and loses the 700 they did not.
     */
    public function clawBack(Model $owner, string $meterKey, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($owner, $meterKey, $units): int {
            $row = $this->locked($owner->getMorphClass(), $owner->getKey(), $meterKey);

            $taken = min($units, max(0, $row->balance));

            if ($taken > 0) {
                $row->update(['balance' => $row->balance - $taken]);
            }

            return $taken;
        }, LockedRow::ATTEMPTS);
    }

    /**
     * Give back units a clawback took, under the row lock, answering the balance after it.
     *
     * Like a grant, they pay off an overdraft the grace allowed first, because the grace was an advance on units the
     * owner turned out to hold. Unlike a grant, they are not counted as bought again: `granted_total` never lost them.
     */
    public function giveBack(Model $owner, string $meterKey, int $units): int
    {
        if ($units <= 0) {
            return $this->balance($owner, $meterKey);
        }

        return DB::transaction(function () use ($owner, $meterKey, $units): int {
            $row = $this->locked($owner->getMorphClass(), $owner->getKey(), $meterKey);
            $repaid = min($units, max(0, $row->debt));

            $row->update([
                'balance' => $row->balance + $units - $repaid,
                'debt' => $row->debt - $repaid,
            ]);

            return $row->balance;
        }, LockedRow::ATTEMPTS);
    }

    /**
     * The owner's balance row for a meter, locked for the caller's transaction. Created if absent —
     * insertOrIgnore, not create: two workers racing the first grant must not both hit a unique violation;
     * the loser falls through and reads the row the winner wrote.
     */
    private function locked(string $ownerType, mixed $ownerId, string $meterKey): PrepaidUnits
    {
        $row = PrepaidUnits::model()::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('meter_key', $meterKey);

        return LockedRow::take($row, [
            'owner_type' => $ownerType,
            'owner_id' => is_scalar($ownerId) ? $ownerId : '',
            'meter_key' => $meterKey,
            'balance' => 0,
            'granted_total' => 0,
            'debt' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
