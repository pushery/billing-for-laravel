<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Models\PrepaidUnits;

/**
 * The prepaid-unit balance: units an owner BOUGHT for a meter, on top of the tier's per-cycle `included`
 * allowance. They are consumed only after `included` is exhausted, and they never expire — the owner paid
 * for them, so they roll across cycles (the owner's rule: `included` expires, prepaid does not).
 *
 * Consumption itself is NOT here: it happens inside UsageMeter's reserve/settle, under the same row lock
 * that defends the ceiling, because a unit that two concurrent requests both spend is a unit sold twice.
 * This class owns only the balance's ENDS — granting it and clawing it back — plus the read the gate and
 * the recorder need.
 *
 * Clawback is the unused-balance model: a refund takes back the units the owner has NOT consumed yet, up
 * to what the refund covers. Units already spent are not reclaimed — the value was delivered, and billing
 * them again after refunding the purchase would charge twice for one thing.
 */
final readonly class PrepaidLedger
{
    /** The owner's remaining prepaid units for a meter. */
    public function balance(Model $owner, string $meterKey): int
    {
        $balance = PrepaidUnits::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->value('balance');

        return is_int($balance) ? $balance : 0;
    }

    /** Grant units. Under the row lock, so two concurrent grants cannot lose one another. */
    public function grant(Model $owner, string $meterKey, int $units): int
    {
        if ($units <= 0) {
            return $this->balance($owner, $meterKey);
        }

        return DB::transaction(function () use ($owner, $meterKey, $units): int {
            $row = $this->locked($owner->getMorphClass(), $owner->getKey(), $meterKey);

            $row->update([
                'balance' => $row->balance + $units,
                'granted_total' => $row->granted_total + $units,
            ]);

            return $row->balance;
        });
    }

    /**
     * Claw back up to $units of UNCONSUMED prepaid units, returning how many were actually taken back.
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
        });
    }

    /**
     * The owner's balance row for a meter, locked for the caller's transaction. Created if absent —
     * insertOrIgnore, not create: two workers racing the first grant must not both hit a unique violation;
     * the loser falls through and reads the row the winner wrote.
     */
    private function locked(string $ownerType, mixed $ownerId, string $meterKey): PrepaidUnits
    {
        PrepaidUnits::query()->insertOrIgnore([
            'owner_type' => $ownerType,
            'owner_id' => is_scalar($ownerId) ? $ownerId : '',
            'meter_key' => $meterKey,
            'balance' => 0,
            'granted_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PrepaidUnits::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('meter_key', $meterKey)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
