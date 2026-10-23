<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\MeterCatalog;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Events\UsageSpendCapRaised;
use Pushery\Billing\Events\UsageSpendCapReached;
use Pushery\Billing\Models\SpendCap;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\MeteredComponent;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\SpendCapCheck;
use Pushery\Billing\ValueObjects\UsageHold;

/**
 * What an owner's usage past the included allowance may cost per billing period: a cap per owner, which the owner
 * sets, and a configured default for an owner who never set one.
 *
 * Off unless `billing.usage.spend_cap.enabled` is true. Then every metered request is measured against the owner's
 * cap. The default, `billing.usage.spend_cap.default` in minor units of `billing.currency`, is zero unless configured
 * otherwise, which allows no usage past the included allowance at all.
 *
 * What is spent is what the period's usage past every allowance costs at each meter's unit price, the way the local
 * billing engine bills it, with the units held for requests in flight counted as used. {@see UsageRecorder::meter()}
 * measures a request and takes its hold under a lock on the owner's cap row, so two requests on two meters take turns
 * and cannot both pass the cap. {@see UsageGate} answers the same question as a cheap read in front of it. Usage
 * recorded through {@see UsageRecorder::record()} has already happened, so it is counted whatever the cap says.
 *
 * The cap stands in one currency. A meter without a unit price costs nothing here. A meter priced in another currency
 * cannot be measured against the cap, so its usage is refused once it would cost something.
 *
 * The first time in a period that the cap stops a request, or that usage recorded past it takes the spend over it,
 * {@see UsageSpendCapReached} fires, once. Raising the cap fires {@see UsageSpendCapRaised}, and the next stop is
 * announced again.
 */
final readonly class SpendCaps
{
    public function __construct(
        private Repository $config,
        private MeterCatalog $meters,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private UsageMeter $counters,
    ) {}

    /** Whether metered usage is measured against a spend cap at all. */
    public function enabled(): bool
    {
        return $this->config->get('billing.usage.spend_cap.enabled') === true;
    }

    /** The cap that applies to the owner: the one they set, or the configured default. */
    public function capOf(Model $owner): Money
    {
        return $this->capFrom($this->rowOf($owner));
    }

    /**
     * Set the owner's cap, or with null return them to the configured default, and answer the cap that applies now.
     *
     * A cap raised above the one that applied before fires {@see UsageSpendCapRaised}, and the next request the cap
     * stops is announced again, so an app that paused on the first announcement hears about the second as well.
     *
     * @throws InvalidArgumentException for a negative cap
     */
    public function set(Model $owner, ?Money $cap): Money
    {
        if ($cap instanceof Money && $cap->isNegative()) {
            throw new InvalidArgumentException('A spend cap cannot be negative.');
        }

        $after = $cap ?? $this->defaultCap();

        $before = DB::transaction(function () use ($owner, $cap, $after): Money {
            $row = $this->lockedRow($owner);
            $before = $this->capFrom($row);
            // A stop announced under the old cap says nothing about a higher one, or about one in another currency.
            $fresh = $this->raises($before, $after) || $after->currency !== $before->currency;

            $row->forceFill([
                'amount_minor' => $cap?->minorUnits,
                'currency' => $cap?->currency,
                'reached_period' => $fresh ? null : $row->reached_period,
            ])->save();

            return $before;
        }, LockedRow::ATTEMPTS);

        if ($this->raises($before, $after)) {
            Event::dispatch(new UsageSpendCapRaised($owner, $before, $after));
        }

        return $after;
    }

    /**
     * The owner's cap measured in the current period against $quantity more units of the component: the cap, what is
     * spent, and what the units would add. Without a component, or for a meter without a unit price, they add nothing.
     */
    public function check(Model $owner, ?MeteredComponent $component = null, int $quantity = 0, ?BillingPeriod $period = null): SpendCapCheck
    {
        return $this->measure($owner, $component, $quantity, $period ?? $this->periods->forOwner($owner), $this->rowOf($owner));
    }

    /**
     * Take the hold $reserve takes, under the lock on the owner's cap row, when the cap admits $quantity more units of
     * the component. Answers the hold, the null of an allowance that refused it, or the check of a cap that did.
     *
     * The lock is what makes the cap hold. Every metered request of the owner takes turns on the one row, and the hold
     * one of them takes is counted by the next, so two requests on two meters cannot both pass the cap. A request the
     * cap stops is announced as {@see self::announce()} describes.
     *
     * @param  Closure(): ?UsageHold  $reserve
     */
    public function admit(Model $owner, ?MeteredComponent $component, BillingPeriod $period, int $quantity, Closure $reserve): UsageHold|SpendCapCheck|null
    {
        if (! $this->enabled() || ! $component instanceof MeteredComponent || ! $component->unitPrice instanceof Money) {
            return $reserve();
        }

        $admitted = DB::transaction(function () use ($owner, $component, $period, $quantity, $reserve): UsageHold|SpendCapCheck|null {
            $check = $this->measure($owner, $component, $quantity, $period, $this->lockedRow($owner));

            return $check->admits() ? $reserve() : $check;
        }, LockedRow::ATTEMPTS);

        if ($admitted instanceof SpendCapCheck) {
            $this->announce($owner, $admitted, $period);
        }

        return $admitted;
    }

    /**
     * Announce that usage recorded after the fact took the owner's spend over the cap.
     *
     * Usage recorded without a hold cannot be refused, because it has already happened. It is counted, and the cap is
     * passed; the announcement tells the app to stop what the cap would have stopped.
     */
    public function announceIfPassed(Model $owner, ?MeteredComponent $component, BillingPeriod $period): void
    {
        if (! $this->enabled() || ! $component instanceof MeteredComponent || ! $component->unitPrice instanceof Money) {
            return;
        }

        $check = $this->measure($owner, null, 0, $period, $this->rowOf($owner));

        if ($check->spent->greaterThan($check->cap)) {
            $this->announce($owner, $check, $period);
        }
    }

    /**
     * Fire {@see UsageSpendCapReached} for the owner, unless it fired in this period already.
     *
     * One conditional statement claims the period, so two requests the cap stops at the same moment announce it once.
     * Raising the cap clears the claim.
     */
    public function announce(Model $owner, SpendCapCheck $check, BillingPeriod $period): void
    {
        $key = $owner->getKey();

        // The row the claim is written on. An owner whose usage was only ever recorded may not have one yet.
        SpendCap::model()::query()->insertOrIgnore([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $key,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $claimed = SpendCap::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $key)
            ->where(static fn (Builder $query): Builder => $query->whereNull('reached_period')->orWhere('reached_period', '!=', $period->key))
            ->update(['reached_period' => $period->key, 'updated_at' => Carbon::now()]) === 1;

        if ($claimed) {
            Event::dispatch(new UsageSpendCapReached($owner, $check->cap, $check->spent, $period->key));
        }
    }

    private function measure(Model $owner, ?MeteredComponent $component, int $quantity, BillingPeriod $period, ?SpendCap $row): SpendCapCheck
    {
        $cap = $this->capFrom($row);
        $spent = Money::zero($cap->currency);

        foreach ($this->meters->forTier($this->tiers->resolve($owner)->key) as $meter) {
            if ($meter->unitPrice instanceof Money && $meter->unitPrice->currency === $cap->currency) {
                $units = $this->counters->billableUnits($owner, $meter->key, $period->key, $meter->included);
                $spent = $spent->plus($this->priceOf($meter->unitPrice, $meter->packageSize, $units));
            }
        }

        if (! $component instanceof MeteredComponent || ! $component->unitPrice instanceof Money || $quantity <= 0) {
            return new SpendCapCheck($cap, $spent, Money::zero($cap->currency));
        }

        $before = $this->counters->billableUnits($owner, $component->key, $period->key, $component->included);
        $after = $this->counters->billableUnits($owner, $component->key, $period->key, $component->included, $quantity);
        $cost = $this->priceOf($component->unitPrice, $component->packageSize, $after)
            ->minus($this->priceOf($component->unitPrice, $component->packageSize, $before));

        return new SpendCapCheck($cap, $spent, $cost);
    }

    /** What billable units cost: whole packages at the unit price, a started package charged in full, as the engine bills them. */
    private function priceOf(Money $unitPrice, int $packageSize, int $units): Money
    {
        $size = max(1, $packageSize);

        return $unitPrice->multipliedBy(intdiv($units + $size - 1, $size));
    }

    private function raises(Money $before, Money $after): bool
    {
        return $after->currency === $before->currency && $after->greaterThan($before);
    }

    private function capFrom(?SpendCap $row): Money
    {
        if ($row instanceof SpendCap && $row->amount_minor !== null && is_string($row->currency) && $row->currency !== '') {
            return Money::of($row->amount_minor, $row->currency);
        }

        return $this->defaultCap();
    }

    private function defaultCap(): Money
    {
        $amount = $this->config->get('billing.usage.spend_cap.default');
        $currency = $this->config->get('billing.currency');

        return Money::of(is_int($amount) && $amount > 0 ? $amount : 0, is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR');
    }

    private function rowOf(Model $owner): ?SpendCap
    {
        return SpendCap::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    /** The owner's row, locked for the caller's transaction, written first where the owner has none. */
    private function lockedRow(Model $owner): SpendCap
    {
        $key = $owner->getKey();

        return LockedRow::take(
            SpendCap::model()::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $key),
            ['owner_type' => $owner->getMorphClass(), 'owner_id' => $key, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        );
    }
}
