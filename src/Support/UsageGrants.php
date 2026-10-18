<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pushery\Billing\Models\UsageGrant;

/**
 * Units of a meter given until a moment, beside the units an owner bought, which never expire.
 *
 * A plan that gives its customers 10 000 sends a month, a campaign that gives 500 credits until the end of the
 * quarter: both are allowances that run out, and both are given rather than bought. {@see UsageMeter} draws on them
 * after the tier's per-cycle allowance and before the bought units, the grant that expires first first. That order
 * is the point of the feature: drawn the other way, a grant expires unused while units that would have lasted are
 * spent.
 *
 * When a grant expires, {@see self::expire()} writes off what is left and records the loss. A grant first pays off
 * the overdraft a blocking meter's grace let the owner run into, and only the rest can be spent.
 */
final readonly class UsageGrants
{
    public function __construct(
        private PrepaidLedger $prepaid,
        private BillingEventLog $log,
    ) {}

    /**
     * Give an owner units of a meter until `$expiresAt`.
     *
     * Idempotent on `$reference`: a renewal that runs twice for the same period gives once, and the second call
     * answers the grant of the first. The units pay off the owner's overdraft on the meter before any of them can
     * be spent.
     *
     * @throws InvalidArgumentException when the units are not positive or the moment has passed
     */
    public function grant(Model $owner, string $meterKey, int $units, CarbonInterface $expiresAt, ?string $reference = null): UsageGrant
    {
        if ($units <= 0) {
            throw new InvalidArgumentException("A usage grant gives a positive number of units, and {$units} is not.");
        }

        if (! $expiresAt->isFuture()) {
            throw new InvalidArgumentException("A usage grant expires in the future, and {$expiresAt->toIso8601String()} has passed.");
        }

        $earlier = $reference === null ? null : $this->granted($owner, $meterKey, $reference);

        if ($earlier instanceof UsageGrant) {
            return $earlier;
        }

        try {
            return DB::transaction(function () use ($owner, $meterKey, $units, $expiresAt, $reference): UsageGrant {
                $repaid = $this->prepaid->repayDebt($owner, $meterKey, $units);

                $grant = UsageGrant::model()::query()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'meter_key' => $meterKey,
                    'units' => $units,
                    'remaining' => $units - $repaid,
                    'reference' => $reference,
                    'granted_at' => Carbon::now(),
                    'expires_at' => $expiresAt,
                ]);

                $this->log->record('usage.granted', $owner, [
                    'meter' => $meterKey,
                    'units' => $units,
                    'repaid' => $repaid,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'reference' => $reference,
                ]);

                return $grant;
            }, LockedRow::ATTEMPTS);
        } catch (UniqueConstraintViolationException $concurrent) {
            // A second run of the same renewal inserted the same reference first. Its transaction paid the overdraft;
            // this one rolled back with its repayment, so answering the earlier grant leaves nothing paid twice. A
            // violation no earlier grant explains travels.
            return ($reference === null ? null : $this->granted($owner, $meterKey, $reference)) ?? throw $concurrent;
        }
    }

    /**
     * Write off what is left of every grant that has expired, and record what was lost.
     *
     * `billing:usage:flush` runs this every minute. A grant is written off once: its row is locked and read again
     * before it is touched, so a draw that spent the last units a moment earlier is never undone.
     *
     * @return int the number of grants written off
     */
    public function expire(?CarbonInterface $now = null): int
    {
        $moment = $now ?? Carbon::now();

        $due = UsageGrant::model()::query()
            ->whereNull('expired_at')
            ->where('expires_at', '<=', $moment)
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($due as $id) {
            $expired += DB::transaction(function () use ($id, $moment): int {
                $grant = UsageGrant::model()::query()->whereKey($id)->lockForUpdate()->first();

                if (! $grant instanceof UsageGrant || $grant->expired_at !== null) {
                    return 0;
                }

                $lost = max(0, $grant->remaining);

                $grant->update(['remaining' => 0, 'expired_units' => $lost, 'expired_at' => $moment]);

                $this->log->record('usage.grant_expired', $grant, [
                    'meter' => $grant->meter_key,
                    'units' => $grant->units,
                    'lost' => $lost,
                    'reference' => $grant->reference,
                ]);

                return 1;
            }, LockedRow::ATTEMPTS);
        }

        return $expired;
    }

    /** The units an owner can still spend from grants on a meter: what is left of every grant that has not expired. */
    public function available(Model $owner, string $meterKey, ?CarbonInterface $now = null): int
    {
        $left = UsageGrant::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->whereNull('expired_at')
            ->where('expires_at', '>', $now ?? Carbon::now())
            ->sum('remaining');

        return (int) $left;
    }

    private function granted(Model $owner, string $meterKey, string $reference): ?UsageGrant
    {
        return UsageGrant::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('meter_key', $meterKey)
            ->where('reference', $reference)
            ->first();
    }
}
