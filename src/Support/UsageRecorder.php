<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Contracts\UsageNotifier;
use Pushery\Billing\Enums\MeteringPolicy;
use Pushery\Billing\Enums\UsageEventState;
use Pushery\Billing\Exceptions\QuotaExceeded;
use Pushery\Billing\Models\UsageEvent;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\UsageHold;
use Throwable;

/**
 * The one entry point an app calls when its customer USES something it will be billed for:
 *
 *     app(UsageRecorder::class)->record($team, 'emails', 42_000, sourceKey: "campaign:{$campaign->id}");
 *
 * It does two things in a single local transaction — move the owner's counter (the gauge and the quota
 * ceiling) and write the outbox row the provider is later billed from — so the number a customer sees
 * and the number they are charged for come from the same write. Nothing is sent to a provider here: a
 * send would make this a distributed transaction, and a failed send would either lose the usage or
 * double-report it. The flusher owns the network.
 *
 * The usage is reported to the provider RAW. The allowance ("first 10 000 free") and the packaging
 * ("per 1 000") live in the provider's price and are applied once, to the period's total. Netting them
 * here as well would give the customer the free allowance twice, and rounding each record up to a whole
 * package would charge ten packages for ten sends of a hundred emails.
 *
 * Usage is recorded from the app's own server-side send path. The package deliberately ships no
 * endpoint that accepts a meter and a quantity — that is quantity injection, the sibling of the price
 * injection the catalogs exist to prevent.
 */
final readonly class UsageRecorder
{
    public function __construct(
        private MeterCatalog $meters,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private UsageMeter $counters,
        private PrepaidLedger $prepaid,
        private Repository $config,
        private UsageNotifier $notifier,
    ) {}

    /**
     * Record usage. Returns false when the call was a duplicate of one already recorded under the same
     * source key — the caller's retry, not an error.
     *
     * @param  ?string  $sourceKey  the caller's idempotency key: the same key records the usage once
     * @param  ?CarbonInterface  $occurredAt  when the usage happened (defaults to now); this, never the
     *                                        flush time, is what the provider bills it into
     */
    public function record(
        Model $owner,
        string $meterKey,
        int $quantity,
        ?string $sourceKey = null,
        ?CarbonInterface $occurredAt = null,
    ): bool {
        if ($quantity <= 0) {
            // A correction is a reversal of the event that was wrong, never a negative one: a negative
            // meter event is not a thing a provider accepts, and it would corrupt the counter.
            throw new InvalidArgumentException('Usage quantity must be positive.');
        }

        $moment = ($occurredAt ?? Carbon::now())->utc();
        $period = $this->periods->forOwner($owner, $moment);
        $component = $this->meters->component($this->tiers->resolve($owner)->key, $meterKey);

        $recorded = $this->write($owner, $meterKey, $component, $quantity, $sourceKey, $moment, $period, null);

        if ($recorded) {
            $this->warnIfRunningOut($owner, $component, $period);
        }

        return $recorded;
    }

    /**
     * Tell the owner their allowance is running out — once per meter per period, and only for a meter that
     * HAS an allowance to run out of.
     *
     * Deliberately after the write's transaction, never inside it: the notice is a fact about usage that is
     * already on the books, and mailing from inside a transaction that may still roll back is how a customer
     * gets warned about usage that never happened. The claim itself is atomic, so two requests crossing the
     * threshold together warn once.
     */
    private function warnIfRunningOut(Model $owner, ?MeteredComponent $component, BillingPeriod $period): void
    {
        $included = $component?->included;

        if (! $component instanceof MeteredComponent || $included === null || $included <= 0) {
            return; // nothing is included, so there is no allowance to run out of
        }

        $used = $this->counters->used($owner, $component->key, $period->key);

        if ($used < (int) ceil($included * $component->warnThreshold)) {
            return; // still comfortably inside the allowance
        }

        if (! $this->counters->claimWarning($owner, $component->key, $period->key)) {
            return; // someone else already warned them this period
        }

        try {
            $this->notifier->quotaWarning($owner, $component->key, $component->label, $used, $included);
        } catch (Throwable $e) {
            // The usage is already on the books and the caller was told it was recorded. A warning that
            // cannot be delivered — an owner with no notification route, a mail transport down — must never
            // turn a successful, money-critical write into a failure the caller retries. Class name only:
            // the exception can carry the customer's address.
            Log::warning('The quota warning could not be delivered.', ['exception' => $e::class]);
        }
    }

    /**
     * The one write: the outbox row the provider is billed from AND the counter the owner is shown, in a
     * single transaction, so the number a customer sees and the number they are charged for come from the
     * same act. A hold, when there is one, is settled inside it — so the allowance it claimed becomes the
     * usage it recorded, atomically.
     */
    private function write(
        Model $owner,
        string $meterKey,
        ?MeteredComponent $component,
        int $quantity,
        ?string $sourceKey,
        CarbonInterface $moment,
        BillingPeriod $period,
        ?UsageHold $hold,
    ): bool {
        // Reportable only when the tier actually bills this meter AND billing is switched on. Otherwise
        // the event is a counter entry: the app still meters (its quota must keep working), but there is
        // nothing to hand a provider, and the flusher must never pick it up.
        $reportable = $component?->isBillable() === true && $this->config->get('billing.enabled') !== false;

        $providerMeter = $component?->providerMeter;
        $included = $component?->included;

        // Minted once, here. The flusher replays this exact identifier on every retry, which is what makes
        // a retry safe: the provider recognizes it and bills the usage once.
        $identifier = (string) Str::ulid();

        return DB::transaction(function () use ($owner, $meterKey, $providerMeter, $quantity, $sourceKey, $moment, $period, $reportable, $hold, $included, $identifier): bool {
            $recorded = UsageEvent::query()->insertOrIgnore([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'meter_key' => $meterKey,
                // Stamped now, not looked up at flush time: an owner who changes tier in between must
                // still have the usage they already incurred billed the way they incurred it.
                'provider_meter' => $reportable ? $providerMeter : null,
                'quantity' => $quantity,
                'prepaid_units' => 0,
                'occurred_at' => $moment,
                'period' => $period->key,
                'identifier' => $identifier,
                'source_key' => $sourceKey,
                'state' => ($reportable ? UsageEventState::Pending : UsageEventState::Local)->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The unique source key lost the race (or lost it earlier): this usage is already on the
            // books. Moving the counter now would double-count it on the gauge.
            if ($recorded === 0) {
                // Hand the allowance back, or a retried job burns allowance it never consumed.
                if ($hold instanceof UsageHold) {
                    $this->counters->release($hold);
                }

                return false;
            }

            // Whichever way it was metered, the counter draws on the free allowance first and on the
            // owner's PREPAID units only past it, and tells us how many prepaid units this usage ate.
            $drawn = $hold instanceof UsageHold
                ? ($this->counters->settle($hold, $quantity) ?? 0)
                : $this->counters->record($owner, $meterKey, $period->key, $quantity, $included);

            // Carried on the EVENT, not derived from the period total: the flusher nets prepaid at the
            // rollup, and a rollup only covers the events folded into it — deriving it from the period
            // would subtract the same units again on the next flush of the same cycle.
            if ($drawn > 0) {
                UsageEvent::query()
                    ->where('identifier', $identifier)
                    ->update(['prepaid_units' => $drawn, 'updated_at' => now()]);
            }

            return true;
        });
    }

    /**
     * Meter a unit of work OVERSELL-SAFELY: claim the allowance, do the work, record what it really used.
     *
     *     $sent = app(UsageRecorder::class)->meter($team, 'emails', 5_000, fn () => $mailer->send($batch));
     *
     * This is the difference between asking and taking. The quota GATE is a point-in-time read: two
     * requests can read the same number and both pass it, so an owner one unit below a hard limit gets
     * through as many times as they can fire requests at once. meter() holds the allowance under a row
     * lock BEFORE the work runs, so the ceiling actually holds.
     *
     * The closure may return the number of units it ACTUALLY consumed — reserve 5 000 sends, make 4 812,
     * return 4 812, and the other 188 go straight back to the allowance. Return null (or nothing) and the
     * whole reservation is recorded.
     *
     * If the work throws, the hold is handed back and the exception is rethrown: the customer is not
     * billed for, and not charged allowance for, work that never happened. If the work is a duplicate of
     * one already recorded under the same source key, the hold is handed back too — otherwise a retried
     * job would burn allowance it never consumed.
     *
     * @param  Closure(): ?int  $work  returns the units actually consumed, or null for all of them
     * @return int the units recorded (0 when the work consumed nothing, or was a duplicate)
     *
     * @throws QuotaExceeded when the meter's policy blocks and the allowance cannot cover the request
     */
    public function meter(
        Model $owner,
        string $meterKey,
        int $quantity,
        Closure $work,
        ?string $sourceKey = null,
        ?CarbonInterface $occurredAt = null,
    ): int {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Usage quantity must be positive.');
        }

        $moment = ($occurredAt ?? Carbon::now())->utc();
        $period = $this->periods->forOwner($owner, $moment);
        $component = $this->meters->component($this->tiers->resolve($owner)->key, $meterKey);

        $hold = $this->counters->reserve(
            $owner,
            $meterKey,
            $period->key,
            $quantity,
            $component?->included,
            // Only a BLOCKING policy refuses past the allowance: a degrade or fair-use meter is billed past
            // it, not refused, so there is nothing to defend and the reservation is granted unconditionally.
            $component instanceof MeteredComponent && $component->policy->isBlocking(),
            Carbon::now()->addSeconds($this->holdSeconds()),
        );

        if (! $hold instanceof UsageHold) {
            // What is left is the free allowance the cycle has not spent PLUS the units the owner bought —
            // the same sum the reservation just refused, so the error reports the number it was measured on.
            $included = $component instanceof MeteredComponent ? ($component->included ?? 0) : 0;
            $remaining = max(0, $included - $this->counters->consumed($owner, $meterKey, $period->key))
                + $this->prepaid->balance($owner, $meterKey);

            throw QuotaExceeded::onMeter(
                $meterKey,
                $component instanceof MeteredComponent ? $component->policy : MeteringPolicy::HardStop,
                $quantity,
                $remaining,
            );
        }

        try {
            $actual = $work();
        } catch (Throwable $e) {
            // The work did not happen, so neither the allowance nor the bill may reflect it.
            $this->counters->release($hold);

            throw $e;
        }

        $used = $actual ?? $quantity;

        if ($used <= 0) {
            $this->counters->release($hold);

            return 0;
        }

        if (! $this->write($owner, $meterKey, $component, $used, $sourceKey, $moment, $period, $hold)) {
            return 0;
        }

        // The oversell-safe path consumes allowance exactly like record() does, so it warns exactly like it:
        // an owner metering through meter() must not be the one who never hears they are running out.
        $this->warnIfRunningOut($owner, $component, $period);

        return $used;
    }

    /** How long a hold stands before the sweeper hands it back. */
    private function holdSeconds(): int
    {
        $seconds = $this->config->get('billing.usage.hold_seconds', 900);

        return is_int($seconds) && $seconds > 0 ? $seconds : 900;
    }
}
