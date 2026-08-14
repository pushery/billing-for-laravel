<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\Events\BuyerProtectionHoldRefunded;
use Pushery\Billing\Events\BuyerProtectionHoldReleased;
use Pushery\Billing\Events\BuyerProtectionResolutionRequired;
use Pushery\Billing\Exceptions\BuyerProtectionMisconfigured;
use Pushery\Billing\Models\BuyerProtectionHold;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;

/**
 * The clock a delayed payout runs on, and the only thing that moves it.
 *
 * ## The money is never here
 *
 * A hold is a note that a payout has not been triggered yet. The funds stay with the payment provider
 * throughout, and releasing is an instruction to it — the platform holds nothing, because holding other
 * people's money is a regulated activity and doing it by accident is doing it without a license. Nothing in
 * this class moves money; it decides when the instruction goes out.
 *
 * ## Two clocks, and only one of them can be stopped
 *
 * The first turns the buyer's silence into consent after a while, and a dispute STOPS it — letting it run
 * would auto-release money the buyer has actively objected to, which is the single outcome the arrangement
 * exists to prevent. The second cannot be stopped by anything, because the provider will not delay a payout
 * forever: past its limit the money goes out regardless, so a decision has to exist before then or the
 * protection is a promise the system cannot keep.
 *
 * ## It never decides a dispute
 *
 * When the second clock runs out on a disputed hold, the state becomes one that says a human has to act. The
 * package has no view on who is right, and inventing one would be worse than saying so.
 */
final readonly class BuyerProtectionClock
{
    /** How long the buyer's silence takes to become consent, where the installation names no figure. */
    private const int DEFAULT_CONFIRM_AFTER_DAYS = 14;

    /** When a decision has to have happened, whatever else is going on. */
    private const int DEFAULT_DECIDE_AFTER_DAYS = 60;

    /** How long the provider will delay a payout at all — the wall both clocks have to finish inside. */
    private const int DEFAULT_PROVIDER_LIMIT_DAYS = 90;

    /** How much room to leave before that wall, so a late run is still a run and not a breach. */
    private const int DEFAULT_MARGIN_DAYS = 20;

    /** The account types that let a payout be held back at all. */
    private const array ACCOUNT_TYPES_WITH_PAYOUT_CONTROL = ['express', 'custom'];

    public function __construct(
        private Repository $config,
        /**
         * How the money actually reaches the seller when a hold is released.
         *
         * Nullable because a driver may not move shares at all — a destination-charge installation never
         * opens a hold in the first place, so it never needs this. Where it IS null and a release happens
         * anyway, the state stops at `ReleasePending`: the platform has decided, the money has not moved,
         * and saying so is the honest outcome. Marking it `Released` would record a payment nobody made.
         */
        private ?MovesMerchantShare $transfers = null,
        /** Where the accounts live, so a release can name the destination the transfer goes to. */
        private ?MerchantAccountDirectory $accounts = null,
        /**
         * How the outcome is announced.
         *
         * This was the one cron-driven class in this directory with no dispatcher, and the one that writes
         * money-bearing columns. An auto-release at 05:00 was invisible to a consuming application: its only
         * channel was the console output of the sweep.
         */
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Start the clock on a sale.
     *
     * The two deadlines are computed once, at the start, and stored. Deriving them later from the row's age
     * would let a change of configuration move a deadline that a buyer was already told about.
     */
    public function hold(
        string $chargeReference,
        Money $charge,
        CarbonInterface $paidAt,
        ?Model $merchant = null,
    ): BuyerProtectionHold {
        $this->assertOperable();

        return BuyerProtectionHold::query()->create([
            'charge_reference' => $chargeReference,
            'merchant_type' => $merchant?->getMorphClass(),
            'merchant_id' => $this->merchantKey($merchant),
            'currency' => $charge->currency,
            'charge_minor' => $charge->minorUnits,
            // The commission, taken off at the moment the hold OPENS rather than only when it is released.
            //
            // It used to default to zero here, which had two consequences and both were quiet. A release
            // pays out `charge_minor - platform_fee_minor`, so every released hold handed the merchant the
            // buyer's full price, commission included. And the balance reader subtracted the buyer's price
            // from the merchant's net, which is two different bases against each other — enough to drive a
            // merchant's available balance below zero while nothing looked wrong.
            'platform_fee_minor' => $this->commissionOn($chargeReference),
            'state' => BuyerProtectionState::AwaitingConfirmation,
            'confirm_by' => $paidAt->copy()->addDays($this->confirmAfterDays()),
            'decide_by' => $paidAt->copy()->addDays($this->decideAfterDays()),
        ]);
    }

    /**
     * What the platform kept on the sale this hold sits over.
     *
     * Read from the charge rather than passed in, because the split was already decided when the sale was
     * recorded and a second calculation here would be a second place for it to drift. Zero where no routed
     * charge exists — an unrouted sale has no platform share to take off, and inventing one would withhold
     * money nobody kept.
     */
    private function commissionOn(string $chargeReference): int
    {
        $charge = MerchantCharge::query()->where('charge_reference', $chargeReference)->first();

        return $charge instanceof MerchantCharge ? (int) $charge->fee_minor : 0;
    }

    /** A merchant's key as the morph column stores it, or nothing where the sale names no merchant. */
    private function merchantKey(?Model $merchant): ?string
    {
        $key = $merchant?->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    /** The buyer says they got what they bought. Nothing waits after that. */
    public function confirm(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        return $this->settleAsRelease($hold);
    }

    /**
     * The buyer says something is wrong, which STOPS the confirmation clock.
     *
     * A dispute raised on the last day would otherwise be overtaken by an auto-release hours later, and the
     * objection would have changed nothing.
     */
    public function dispute(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        $hold->state = BuyerProtectionState::Disputed;
        $hold->save();

        return $hold;
    }

    /**
     * Somebody decided. The package never gets here on its own.
     *
     * A hold that is already finished is refused rather than moved again: releasing or refunding twice sends
     * the same money twice, and the second instruction looks exactly like the first.
     */
    public function resolve(BuyerProtectionHold $hold, bool $releaseToSeller, ?Money $refund = null): BuyerProtectionHold
    {
        if ($hold->state->settled()) {
            throw BuyerProtectionMisconfigured::alreadySettled($hold->charge_reference, $hold->state->value);
        }

        return $releaseToSeller
            ? $this->settleAsRelease($hold)
            : $this->settleAsRefund($hold, $refund ?? Money::of($hold->charge_minor, $hold->currency));
    }

    /**
     * Move every hold whose time has come, and return what changed.
     *
     * Two passes rather than one, because the two clocks answer different questions and a hold can be past
     * both. The decision pass runs LAST so that a hold which is both unconfirmed and out of time settles
     * rather than merely becoming due — being past the second deadline is the stronger fact.
     *
     * @return list<BuyerProtectionHold>
     */
    public function advance(CarbonInterface $now): array
    {
        $moved = [];

        foreach ($this->dueForAutoRelease($now) as $hold) {
            $moved[] = $this->settleAsRelease($hold);
        }

        foreach ($this->dueForDecision($now) as $hold) {
            // A disputed hold is not auto-released — nobody here knows who is right. It becomes a hold that
            // says so, which is a state somebody can act on rather than a silent decision nobody made.
            $moved[] = $hold->state === BuyerProtectionState::Disputed
                ? $this->markResolutionRequired($hold)
                : $this->settleAsRelease($hold);
        }

        return $moved;
    }

    /**
     * Holds the buyer never said anything about, past the day silence becomes consent.
     *
     * @return list<BuyerProtectionHold>
     */
    private function dueForAutoRelease(CarbonInterface $now): array
    {
        /** @var list<BuyerProtectionHold> $rows */
        $rows = BuyerProtectionHold::query()
            // Asked of the enum rather than named here, so a state that starts running the clock is picked up
            // instead of being silently left out of the one query that would have moved it.
            ->whereIn('state', $this->statesWhoseClockRuns())
            ->where('confirm_by', '<=', $now)
            ->where('decide_by', '>', $now)
            ->get()
            ->all();

        return $rows;
    }

    /**
     * The states in which the confirmation clock is running, as the enum defines them.
     *
     * @return list<string>
     */
    private function statesWhoseClockRuns(): array
    {
        return array_values(array_map(
            static fn (BuyerProtectionState $state): string => $state->value,
            array_filter(BuyerProtectionState::cases(), static fn (BuyerProtectionState $state): bool => $state->clockRuns()),
        ));
    }

    /**
     * Holds past the deadline nothing can stop.
     *
     * @return list<BuyerProtectionHold>
     */
    private function dueForDecision(CarbonInterface $now): array
    {
        /** @var list<BuyerProtectionHold> $rows */
        $rows = BuyerProtectionHold::query()
            ->whereIn('state', [
                BuyerProtectionState::AwaitingConfirmation->value,
                BuyerProtectionState::Disputed->value,
            ])
            ->where('decide_by', '<=', $now)
            ->get()
            ->all();

        return $rows;
    }

    private function settleAsRelease(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        // Already settled: a sweep that runs twice, an overlapping cron, a retried confirmation. Paying a
        // merchant a second time is the expensive direction, so the guard is here rather than in each of
        // the four callers that can reach this.
        if ($hold->settled_at !== null) {
            return $hold;
        }

        // The seller gets what is left after the platform's own fee. The three figures are written together
        // and always sum to the charge, so no end state can quietly lose or invent a cent.
        $hold->seller_net_minor = $hold->charge_minor - $hold->platform_fee_minor;
        $hold->buyer_refund_minor = 0;

        // RELEASE PENDING, before the money moves. The state existed and nothing ever set it, and this is
        // the moment it describes: the platform has decided, the provider has not confirmed. A row that went
        // straight to `Released` would claim a payment that had not happened yet — and if the transfer
        // throws, that claim is what an operator would be left reading.
        $hold->state = BuyerProtectionState::ReleasePending;
        $hold->save();

        $moved = $this->payOut($hold);

        // `ReleasePending` is left behind only when the transfer actually happened — or when there was
        // nothing to transfer. If `payOut()` THROWS, the state stays pending and the exception travels: that
        // is the one case the state was declared for and never reached, and it is exactly the case an
        // operator has to see. A row that said `Released` after a failed transfer would be a payment claimed
        // and not made.
        if ($moved) {
            $hold->state = BuyerProtectionState::Released;
            $hold->settled_at = $hold->freshTimestamp();
            $hold->save();

            $this->announce(new BuyerProtectionHoldReleased(
                $hold->charge_reference,
                $hold->merchant_type,
                $hold->merchant_id,
                Money::of($hold->seller_net_minor, $hold->currency),
                $hold->state,
            ));
        }

        return $hold;
    }

    /**
     * Instruct the provider to move the seller's share.
     *
     * Keyed on the CHARGE row, the same idempotency rule the immediate lane already documents — so a sweep
     * that runs twice over one hold instructs one transfer. The key has to be the sale rather than the hold
     * because the two lanes must not be able to pay for the same sale twice between them.
     */
    private function payOut(BuyerProtectionHold $hold): bool
    {
        // Nothing to move, so the release is COMPLETE rather than stuck. An unrouted sale has no merchant to
        // pay: the platform kept the money and the protection period simply ended. Reporting that as
        // "decided but not paid" would leave a hold hanging forever over a payment nobody was owed.
        if ($hold->merchant_type === null || $hold->merchant_id === null) {
            return true;
        }

        // No way to move a share at all — a driver that does not support it, an installation that never
        // bound one. Nothing is owed to the provider here, so the release is complete rather than stuck:
        // leaving it pending would park a hold forever over a transfer this arrangement was never going to
        // make. `assertOperable()` is what refuses an arrangement that claims protection it cannot deliver;
        // this method's job is only to move money where there is a way to.
        if (! $this->transfers instanceof MovesMerchantShare || ! $this->accounts instanceof MerchantAccountDirectory) {
            return true;
        }

        // Resolved from the morph columns rather than through a relation the model does not declare, and
        // by hand rather than lazily: a class that no longer exists — deleted, renamed, never migrated — is
        // an ordinary answer here and must not take a sweep down for one row.
        $class = Relation::getMorphedModel((string) $hold->merchant_type) ?? (string) $hold->merchant_type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return false;
        }

        $merchant = $class::query()->find($hold->merchant_id);

        if (! $merchant instanceof Model) {
            return false;
        }

        $destination = $this->accounts->accountFor($merchant);

        // A merchant with no account at the provider. There is nobody to transfer TO, and the hold must not
        // hang on it — the money is the platform's problem to resolve, not a state machine's.
        if (! $destination instanceof MerchantAccountReference) {
            return true;
        }

        $charge = MerchantCharge::query()->where('charge_reference', $hold->charge_reference)->first();

        $this->transfers->transferShare(
            $destination,
            Money::of($hold->seller_net_minor, $hold->currency),
            $hold->charge_reference,
            $charge instanceof MerchantCharge ? "billing_merchant_charge_{$charge->id}" : "billing_protection_hold_{$hold->id}",
        );

        return true;
    }

    /** Announce an outcome, where anything is listening. */
    private function announce(object $event): void
    {
        $this->events?->dispatch($event);
    }

    private function settleAsRefund(BuyerProtectionHold $hold, Money $refund): BuyerProtectionHold
    {
        $hold->state = BuyerProtectionState::Refunded;
        $hold->buyer_refund_minor = $refund->minorUnits;
        // What the buyer did not get back is what the platform and the seller keep between them, and the fee
        // is the platform's part of it. A refund that left the fee standing against a smaller remainder would
        // make the three figures stop summing to the charge.
        $hold->platform_fee_minor = min($hold->platform_fee_minor, $hold->charge_minor - $refund->minorUnits);
        $hold->seller_net_minor = $hold->charge_minor - $refund->minorUnits - $hold->platform_fee_minor;
        $hold->settled_at = $hold->freshTimestamp();
        $hold->save();

        $this->announce(new BuyerProtectionHoldRefunded(
            $hold->charge_reference,
            $hold->merchant_type,
            $hold->merchant_id,
            $refund,
            $hold->state,
        ));

        return $hold;
    }

    private function markResolutionRequired(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        $hold->state = BuyerProtectionState::ResolutionRequired;
        $hold->save();

        // The one outcome that NEEDS somebody to hear it: the package has deliberately not decided, and if
        // nothing is listening the hold sits in that state indefinitely with a buyer's money in it.
        $this->announce(new BuyerProtectionResolutionRequired(
            $hold->charge_reference,
            $hold->merchant_type,
            $hold->merchant_id,
            Money::of($hold->charge_minor, $hold->currency),
            $hold->state,
        ));

        return $hold;
    }

    /**
     * Refuse an arrangement that cannot do what it claims.
     *
     * Checked where a hold is created rather than only at boot, because the two failures it catches are both
     * about money already taken from a buyer — and a configuration can change after boot.
     */
    public function assertOperable(): void
    {
        $accountType = $this->config->get('billing.marketplace.buyer_protection.account_type', 'express');

        if (! in_array($accountType, self::ACCOUNT_TYPES_WITH_PAYOUT_CONTROL, true)) {
            throw BuyerProtectionMisconfigured::accountTypeWithoutPayoutControl(
                is_scalar($accountType) ? (string) $accountType : gettype($accountType)
            );
        }

        $decide = $this->decideAfterDays();
        $limit = $this->days('provider_limit_days', self::DEFAULT_PROVIDER_LIMIT_DAYS);
        $margin = $this->days('margin_days', self::DEFAULT_MARGIN_DAYS);

        if ($limit - $decide < $margin) {
            throw BuyerProtectionMisconfigured::decisionBeyondProviderLimit($decide, $limit, $limit - $decide);
        }
    }

    public function confirmAfterDays(): int
    {
        return $this->days('confirm_after_days', self::DEFAULT_CONFIRM_AFTER_DAYS);
    }

    public function decideAfterDays(): int
    {
        return $this->days('decide_after_days', self::DEFAULT_DECIDE_AFTER_DAYS);
    }

    private function days(string $key, int $default): int
    {
        $value = $this->config->get("billing.marketplace.buyer_protection.{$key}");

        return is_int($value) ? $value : $default;
    }
}
