<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pushery\Billing\Enums\ReservationState;
use Pushery\Billing\Models\PrepaidUnits;
use Pushery\Billing\Models\UsageCounter;
use Pushery\Billing\Models\UsageReservation;
use Pushery\Billing\ValueObjects\UsageHold;

/**
 * Atomic metered-usage accounting, per owner per METER per period.
 *
 * WHY THE LOCK IS THE WHOLE FEATURE. A quota check that reads the counter, decides, and writes later
 * cannot hold a ceiling: two requests read the same number and both pass it. An owner one unit below their
 * limit gets through as many times as they can fire requests in parallel. reserve() closes that by making
 * the check and the claim ONE act under the counter's row lock — it does not ask whether there is room,
 * it TAKES the room.
 *
 * A hold is settled exactly once, keyed on its token, so a retried job cannot spend the same allowance
 * twice or hand it back twice. A hold nobody settles EXPIRES (see expire()): a worker killed between
 * reserving and settling would otherwise hold that allowance for the rest of the billing period, and a
 * leaked hold REFUSES A PAYING CUSTOMER — worse than the oversell it was meant to prevent.
 *
 * TWO SOURCES OF ALLOWANCE, CONSUMED IN ORDER. The tier's `included` allowance is PER CYCLE and lives in
 * this (period-scoped) counter, so it expires with the cycle. PREPAID units are bought outright, live in a
 * persistent balance, and never expire. Usage draws on `included` FIRST and only then on prepaid — so the
 * free allowance is spent before the units the owner paid for, which is the only order that does not quietly
 * waste their money. Both are defended by the SAME transaction: the prepaid row is locked together with the
 * counter (counter first, prepaid second — always that order, so two requests can never deadlock), because a
 * prepaid unit that two concurrent requests both spend is a unit sold twice.
 *
 * Prepaid is drawn down at SETTLE, not at reserve: what a hold really consumed is only known once the work
 * is done. The draw is the delta of this settlement — how much further past `included` the period's usage
 * moved — capped at the balance that is actually there.
 */
final class UsageMeter
{
    /**
     * Claim $amount of allowance, or answer null when it would breach the ceiling. The check and the claim
     * happen in one transaction, under the counter's (and the prepaid balance's) row lock. That is the
     * guarantee.
     *
     * @param  ?int  $included  the tier's per-cycle free allowance (null when the meter has none)
     * @param  bool  $enforce  whether to REFUSE past the allowance (a blocking policy) or always grant
     *                         (degrade/fair-use meters are billed past it, not refused)
     */
    public function reserve(
        Model $owner,
        string $meterKey,
        string $period,
        int $amount,
        ?int $included,
        bool $enforce,
        ?CarbonInterface $expiresAt = null,
    ): ?UsageHold {
        return DB::transaction(function () use ($owner, $meterKey, $period, $amount, $included, $enforce, $expiresAt): ?UsageHold {
            $counter = $this->lockedCounter($owner, $meterKey, $period);
            $prepaid = $this->lockedPrepaid($owner->getMorphClass(), $owner->getKey(), $meterKey);

            if ($enforce && $included !== null) {
                // What the owner can still take: the cycle's UNSPENT free allowance, plus the units they
                // bought, minus everything already promised to requests in flight.
                //
                // Holds are subtracted from the SUM, not from the free part alone. Subtracting them from
                // `included` only would leave the prepaid balance undefended: with no free allowance at all,
                // a held unit would reduce nothing, and two concurrent requests would both be handed the same
                // bought unit — a unit the customer has already PAID for, sold twice.
                //
                // `used` is not double-counted against prepaid: the free part is capped at `included`, and
                // whatever went past it has already been taken out of the balance (see drawPrepaid).
                $prepaidLeft = $prepaid instanceof PrepaidUnits ? max(0, $prepaid->balance) : 0;
                $available = max(0, max(0, $included - $counter->used) + $prepaidLeft - $counter->reserved);

                if ($amount > $available) {
                    return null;
                }
            }

            $counter->update(['reserved' => $counter->reserved + $amount]);

            $hold = new UsageHold((string) Str::ulid(), $meterKey, $period, $amount);

            UsageReservation::query()->create([
                'token' => $hold->token,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'meter_key' => $meterKey,
                'period' => $period,
                'amount' => $amount,
                // Frozen with the hold: settle() needs the allowance line THIS hold was taken against, so a
                // tier change between reserving and settling cannot silently move it.
                'included' => $included,
                'state' => ReservationState::Pending,
                'expires_at' => $expiresAt ?? Carbon::now()->addMinutes(15),
            ]);

            return $hold;
        });
    }

    /**
     * Turn a hold into used units. $used defaults to the whole hold; passing less hands the remainder back
     * — a request that reserved 5 000 sends and made only 4 812 must not burn the other 188.
     *
     * Idempotent: settling an already-settled hold changes nothing and answers null.
     *
     * @return ?int the units this settlement drew from the PREPAID balance (0 when it stayed inside the
     *              cycle's free allowance), or NULL when there was no hold of ours left to settle
     */
    public function settle(UsageHold $hold, ?int $used = null): ?int
    {
        return $this->settleToken($hold->token, $used ?? $hold->amount, ReservationState::Committed);
    }

    /** Hand a hold back unused. Idempotent, for the same reason settling is. A release consumes no prepaid. */
    public function release(UsageHold $hold): bool
    {
        return $this->settleToken($hold->token, 0, ReservationState::Released) !== null;
    }

    /**
     * Record used units with no hold — the path an app takes when it meters AFTER the fact.
     *
     * It deliberately does NOT touch `reserved`. It used to, and that was a defect: a record that never
     * reserved anything would eat an unrelated request's in-flight hold, which meant the two ways of
     * metering could not be used in the same application. It draws on prepaid exactly as a settle does, so
     * both ways of metering spend the same allowance in the same order.
     *
     * @return int the units this record drew from the prepaid balance
     */
    public function record(Model $owner, string $meterKey, string $period, int $amount, ?int $included = null): int
    {
        return DB::transaction(function () use ($owner, $meterKey, $period, $amount, $included): int {
            $counter = $this->lockedCounter($owner, $meterKey, $period);
            $prepaid = $this->lockedPrepaid($owner->getMorphClass(), $owner->getKey(), $meterKey);

            $drawn = $this->drawPrepaid($counter, $prepaid, $amount, $included);

            $counter->update([
                'used' => $counter->used + $amount,
                'prepaid_used' => $counter->prepaid_used + $drawn,
            ]);

            return $drawn;
        });
    }

    /**
     * Release every hold nobody settled in time, giving the allowance back.
     *
     * Not housekeeping — the safety valve. Without it, one worker killed mid-request silently shrinks an
     * owner's allowance for the rest of the cycle, and they start seeing a limit they never reached.
     *
     * @return int the number of holds reclaimed
     */
    public function expire(?CarbonInterface $now = null): int
    {
        $expired = UsageReservation::query()
            ->where('state', ReservationState::Pending)
            ->where('expires_at', '<=', $now ?? Carbon::now())
            ->get();

        $reclaimed = 0;

        foreach ($expired as $reservation) {
            $reclaimed += $this->settleToken($reservation->token, 0, ReservationState::Released) !== null ? 1 : 0;
        }

        return $reclaimed;
    }

    /** Units USED on a meter in a period — what the owner is billed for, and what the gauge renders. */
    public function used(Model $owner, string $meterKey, string $period): int
    {
        return $this->counterValue($owner, $meterKey, $period, 'used');
    }

    /**
     * Claim the right to warn this owner that this meter is running out, ONCE for this period.
     *
     * It is a single conditional UPDATE, not a read-then-write: two requests crossing the threshold at the
     * same instant would both see `warned_at IS NULL` and both mail the customer. Only the update that
     * actually flips the column reports true, so exactly one of them sends. The next period is a fresh
     * counter row, so the owner is warned again when it matters again.
     *
     * @return bool true for the caller that won the claim — the one that must send the notice
     */
    public function claimWarning(Model $owner, string $meterKey, string $period): bool
    {
        return UsageCounter::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->where('period', $period)
            ->whereNull('warned_at')
            ->update(['warned_at' => Carbon::now()]) === 1;
    }

    /**
     * Units SPENT on a meter in a period: used plus held. A ceiling has to be measured against this —
     * allowance promised to a request in flight is not allowance the owner still has.
     */
    public function consumed(Model $owner, string $meterKey, string $period): int
    {
        return $this->used($owner, $meterKey, $period)
            + $this->counterValue($owner, $meterKey, $period, 'reserved');
    }

    /**
     * Settle one hold, once: take the whole hold off the ceiling, add whatever it really used, draw the part
     * that fell past the free allowance from prepaid, and stamp the row. Every way a hold can end —
     * committed, released, expired — comes through here, so the "this one is already settled" check exists
     * in exactly one place and cannot drift between them.
     *
     * @return ?int units drawn from prepaid, or null when the hold was not ours to settle
     */
    private function settleToken(string $token, int $used, ReservationState $state): ?int
    {
        return DB::transaction(function () use ($token, $used, $state): ?int {
            $reservation = UsageReservation::query()->where('token', $token)->lockForUpdate()->first();

            // Not ours to settle: no such hold, or someone settled it first (the sweep reads, then writes,
            // and a request can commit its hold in between — reclaiming it then would hand back allowance
            // that has already become usage).
            if (! $reservation instanceof UsageReservation || $reservation->state->isSettled()) {
                return null;
            }

            $counter = $this->lockedCounterFor(
                $reservation->owner_type,
                $reservation->owner_id,
                $reservation->meter_key,
                $reservation->period,
            );

            $prepaid = $this->lockedPrepaid($reservation->owner_type, $reservation->owner_id, $reservation->meter_key);

            $settled = max(0, $used);
            $drawn = $this->drawPrepaid($counter, $prepaid, $settled, $reservation->included);

            $counter->update([
                'used' => $counter->used + $settled,
                // The FULL hold comes off, never only the part that was used — anything else leaks the
                // remainder of every partial settlement back into the ceiling.
                'reserved' => max(0, $counter->reserved - $reservation->amount),
                'prepaid_used' => $counter->prepaid_used + $drawn,
            ]);

            $reservation->update(['state' => $state]);

            return $drawn;
        });
    }

    /**
     * How much of $amount falls PAST the cycle's free allowance and must therefore come out of prepaid —
     * and take it out.
     *
     * The delta is what matters, not the total: the period's usage moves from `used` to `used + amount`, and
     * only the part of that move which lies beyond `included` draws on prepaid. Everything at or below
     * `included` is free, and is spent first. The draw is capped at the balance that actually exists,
     * because a non-blocking meter is allowed past its allowance (it is billed, not refused) and would
     * otherwise push the balance negative.
     *
     * A meter with no `included` has no free allowance at all, so prepaid covers it from the first unit.
     */
    private function drawPrepaid(UsageCounter $counter, ?PrepaidUnits $prepaid, int $amount, ?int $included): int
    {
        if ($amount <= 0 || ! $prepaid instanceof PrepaidUnits || $prepaid->balance <= 0) {
            return 0;
        }

        $free = max(0, $included ?? 0);
        $before = $counter->used;
        $after = $before + $amount;

        $beyond = max(0, $after - $free) - max(0, $before - $free);
        $drawn = min($beyond, $prepaid->balance);

        if ($drawn > 0) {
            $prepaid->update(['balance' => $prepaid->balance - $drawn]);
        }

        return $drawn;
    }

    private function counterValue(Model $owner, string $meterKey, string $period, string $column): int
    {
        $value = UsageCounter::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->where('period', $period)
            ->value($column);

        return is_int($value) ? $value : 0;
    }

    private function lockedCounter(Model $owner, string $meterKey, string $period): UsageCounter
    {
        $key = $owner->getKey();

        return $this->lockedCounterFor(
            $owner->getMorphClass(),
            is_scalar($key) ? $key : '',
            $meterKey,
            $period,
        );
    }

    /**
     * The owner's counter row for this meter and period, locked for the caller's transaction. Created if it
     * does not exist yet — insertOrIgnore, not create: two workers racing the first request for a brand-new
     * counter must not both get a unique violation. The loser falls through and reads the row the winner
     * wrote.
     */
    private function lockedCounterFor(string $ownerType, mixed $ownerId, string $meterKey, string $period): UsageCounter
    {
        UsageCounter::query()->insertOrIgnore([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'meter_key' => $meterKey,
            'period' => $period,
            'used' => 0,
            'reserved' => 0,
            'prepaid_used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return UsageCounter::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('meter_key', $meterKey)
            ->where('period', $period)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The owner's prepaid balance row for this meter, locked for the caller's transaction — or null when
     * they have none.
     *
     * Deliberately does NOT create the row: an owner who never bought prepaid units must not get one written
     * for them on every single usage record. Locked AFTER the counter, always in that order, so the two
     * rows can never be taken in opposite orders by two requests and deadlock.
     */
    private function lockedPrepaid(string $ownerType, mixed $ownerId, string $meterKey): ?PrepaidUnits
    {
        return PrepaidUnits::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('meter_key', $meterKey)
            ->lockForUpdate()
            ->first();
    }
}
