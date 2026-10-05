<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MovesMerchantShare;
use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Events\BuyerProtectionHoldRefunded;
use Pushery\Billing\Events\BuyerProtectionHoldReleased;
use Pushery\Billing\Events\BuyerProtectionResolutionRequired;
use Pushery\Billing\Exceptions\BuyerProtectionMisconfigured;
use Pushery\Billing\Models\BuyerProtectionHold;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Support\OwnerOfRecord;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TransferResult;
use Throwable;

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
        /**
         * Where a released share is written down on the sale's own row.
         *
         * The release transferred the share and wrote nothing back, so a protected sale stayed `pending` after
         * the merchant was paid: the readers that count settled sales never counted it, and a lost dispute found
         * no transfer to reverse. Nullable for the same reason as the seams above.
         */
        private ?RoutedChargeLedger $ledger = null,
        /**
         * Whether the merchant's payouts are withheld, asked before a released share moves.
         *
         * Null where nothing is asked, which is how a hand-built clock behaves.
         */
        private ?MerchantPayoutGate $payouts = null,
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

        return BuyerProtectionHold::model()::query()->create([
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
        $charge = MerchantCharge::model()::query()->where('charge_reference', $chargeReference)->first();

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
        // A confirmation of a hold somebody already decided changes nothing and answers with the hold as it stands.
        return $this->settleAsRelease($hold) ?? $hold->refresh();
    }

    /**
     * The buyer says something is wrong, which STOPS the confirmation clock.
     *
     * A dispute raised on the last day would otherwise be overtaken by an auto-release hours later, and the
     * objection would have changed nothing.
     *
     * A finished hold is refused, as resolve() refuses it. Its state is the only thing that says the money
     * has moved, and a released hold put back to `Disputed` could be refunded on top of the payout. So is a
     * release still waiting on its transfer: the share is owed, and the retry of unmoved shares moves it.
     */
    public function dispute(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        if ($this->decided($hold)) {
            throw BuyerProtectionMisconfigured::alreadySettled($hold->charge_reference, $hold->state->value);
        }

        $disputed = $this->decideUnderLock($hold, static function (BuyerProtectionHold $locked): void {
            $locked->state = BuyerProtectionState::Disputed;
        });

        return $disputed ?? $this->refuseDecided($hold);
    }

    /**
     * Somebody decided. The package never gets here on its own.
     *
     * A hold that is already finished is refused rather than moved again: releasing or refunding twice sends
     * the same money twice, and the second instruction looks exactly like the first. A release still waiting on
     * its transfer is refused as well. It was decided, its share is owed and moves through the retry of unmoved
     * shares, and a refund decided over it would pay the buyer back money the merchant is being paid.
     */
    public function resolve(BuyerProtectionHold $hold, bool $releaseToSeller, ?Money $refund = null): BuyerProtectionHold
    {
        if ($this->decided($hold)) {
            throw BuyerProtectionMisconfigured::alreadySettled($hold->charge_reference, $hold->state->value);
        }

        $settled = $releaseToSeller
            ? $this->settleAsRelease($hold)
            : $this->settleAsRefund($hold, $refund ?? Money::of($hold->charge_minor, $hold->currency));

        return $settled ?? $this->refuseDecided($hold);
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

        // A hold somebody decided while the sweep was on its way is not one the sweep moved.
        return array_values(array_filter($moved, static fn (?BuyerProtectionHold $hold): bool => $hold instanceof BuyerProtectionHold));
    }

    /**
     * Holds the buyer never said anything about, past the day silence becomes consent.
     *
     * @return list<BuyerProtectionHold>
     */
    private function dueForAutoRelease(CarbonInterface $now): array
    {
        /** @var list<BuyerProtectionHold> $rows */
        $rows = BuyerProtectionHold::model()::query()
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
        $rows = BuyerProtectionHold::model()::query()
            ->whereIn('state', [
                BuyerProtectionState::AwaitingConfirmation->value,
                BuyerProtectionState::Disputed->value,
            ])
            ->where('decide_by', '<=', $now)
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Release the seller's share, or nothing where the hold was decided first.
     *
     * Already decided: a sweep that runs twice, an overlapping cron, a retried confirmation, or a refund issued
     * while the sweep was on its way. Paying a merchant a second time, or paying for money the buyer got back, is
     * the expensive direction, so the guard is here rather than in each of the four callers that can reach this,
     * and it reads the row under its lock rather than the copy the caller loaded.
     *
     * One decided hold is taken up again: a release that stopped on a failed transfer. The payout then asks the
     * provider for a transfer the failed attempt may have made before it makes one (`releaseStopped()`).
     */
    private function settleAsRelease(BuyerProtectionHold $hold): ?BuyerProtectionHold
    {
        // RELEASE PENDING, before the money moves. The state existed and nothing ever set it, and this is
        // the moment it describes: the platform has decided, the provider has not confirmed. A row that went
        // straight to `Released` would claim a payment that had not happened yet — and if the transfer
        // throws, that claim is what an operator would be left reading.
        $hold = $this->decideUnderLock($hold, static function (BuyerProtectionHold $locked): void {
            // The seller gets what is left after the platform's own fee. The three figures are written together
            // and always sum to the charge, so no end state can quietly lose or invent a cent.
            $locked->seller_net_minor = $locked->charge_minor - $locked->platform_fee_minor;
            $locked->buyer_refund_minor = 0;
            $locked->state = BuyerProtectionState::ReleasePending;
        }, $this->releaseStopped(...));

        if (! $hold instanceof BuyerProtectionHold) {
            return null;
        }

        // Outside the lock: the provider is asked after the decision is committed, and a release that is
        // pending is one every other decision now refuses.
        $moved = $this->payOut($hold);

        // `ReleasePending` is left behind only when the transfer actually happened — or when there was
        // nothing to transfer. If `payOut()` THROWS, the state stays pending and the exception travels: that
        // is the one case the state was declared for and never reached, and it is exactly the case an
        // operator has to see. A row that said `Released` after a failed transfer would be a payment claimed
        // and not made.
        if ($moved) {
            $this->markReleased($hold);
        }

        return $hold;
    }

    /**
     * Finish a release whose share moved after the release itself had stopped.
     *
     * A release whose transfer failed, or whose merchant's payouts were withheld, leaves its hold `ReleasePending`,
     * and the share moves later through the retry of unmoved shares, which settles the sale. Nothing finished the
     * hold over it: the balance went on reporting the paid share as held, and the release was never announced.
     * The retry calls this once it has settled the sale. A hold in any other state is left as it is, because only
     * a decided release waits on a transfer.
     */
    public function shareMoved(string $chargeReference): ?BuyerProtectionHold
    {
        $hold = BuyerProtectionHold::model()::query()
            ->where('charge_reference', $chargeReference)
            ->where('state', BuyerProtectionState::ReleasePending->value)
            ->whereNull('settled_at')
            ->first();

        if (! $hold instanceof BuyerProtectionHold) {
            return null;
        }

        $this->markReleased($hold);

        return $hold;
    }

    /** The release is complete: the hold says so, and the outcome is announced. */
    private function markReleased(BuyerProtectionHold $hold): void
    {
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

    /**
     * Change a hold under its row lock, and only if nobody decided it first.
     *
     * Every decision here starts from a hold somebody loaded earlier: the sweep loads the holds it will move before
     * it moves any, and a person decides from the hold a screen showed. Another decision can land in between, a
     * refund issued while the release run was on its way, and a change written from the loaded copy then wrote the
     * release over the refund and paid the merchant money the buyer got back. The row is read again under its lock,
     * and a hold that is decided by then is left as it is: null says so, unless `$resumes` takes it up again.
     *
     * @param  Closure(BuyerProtectionHold): void  $change
     * @param  (Closure(BuyerProtectionHold): bool)|null  $resumes  which decided hold this decision may take up again
     */
    private function decideUnderLock(BuyerProtectionHold $hold, Closure $change, ?Closure $resumes = null): ?BuyerProtectionHold
    {
        return $hold->getConnection()->transaction(function () use ($hold, $change, $resumes): ?BuyerProtectionHold {
            $locked = BuyerProtectionHold::model()::query()->whereKey($hold->getKey())->lockForUpdate()->first();

            if (! $locked instanceof BuyerProtectionHold) {
                return null;
            }

            if ($this->decided($locked) && (! $resumes instanceof Closure || ! $resumes($locked))) {
                return null;
            }

            $change($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Whether a release still pending stopped on a failed transfer, so that the next release may take it up.
     *
     * The failed attempt may have reached the provider and lost its answer, and the payout asks for that transfer
     * before it makes one. A release still on its way has recorded no failure: a second confirmation that took it
     * up would ask the provider for the same share while the first request is open. That one is left to finish,
     * and a release stopped without a failure, a killed worker, is moved by the retry of unmoved shares.
     */
    private function releaseStopped(BuyerProtectionHold $hold): bool
    {
        if ($hold->state !== BuyerProtectionState::ReleasePending || $hold->settled_at !== null) {
            return false;
        }

        $charge = MerchantCharge::model()::query()->where('charge_reference', $hold->charge_reference)->first();

        return $charge instanceof MerchantCharge && $charge->transfer_failed_at !== null;
    }

    /** Refuse a person's decision over a hold that was decided while they were deciding. */
    private function refuseDecided(BuyerProtectionHold $hold): never
    {
        $hold->refresh();

        throw BuyerProtectionMisconfigured::alreadySettled($hold->charge_reference, $hold->state->value);
    }

    /** Whether the hold's outcome is decided: finished either way, or a release still waiting on its transfer. */
    private function decided(BuyerProtectionHold $hold): bool
    {
        return $hold->state->settled() || $hold->state === BuyerProtectionState::ReleasePending || $hold->settled_at !== null;
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

        // Resolved from the morph columns rather than through a relation the model does not declare: a class that
        // no longer exists — deleted, renamed, never migrated — is an ordinary answer here and must not take a sweep
        // down for one row. And the share is owed for a sale that already happened, so a merchant the application
        // has soft-deleted or scoped away since is still the one it is owed to.
        $merchant = OwnerOfRecord::find((string) $hold->merchant_type, $hold->merchant_id);

        if (! $merchant instanceof Model) {
            return false;
        }

        $destination = $this->accounts->accountFor($merchant);

        // A merchant with no account at the provider. There is nobody to transfer TO, and the hold must not
        // hang on it — the money is the platform's problem to resolve, not a state machine's.
        if (! $destination instanceof MerchantAccountReference) {
            return true;
        }

        $charge = MerchantCharge::model()::query()->where('charge_reference', $hold->charge_reference)->first();

        // Moved already, by the retry of a failed transfer or by the release of a withheld share. Both move the
        // SALE, under its own key, and a hold released after them must not instruct a second transfer: the key
        // only protects inside the provider's replay window, and a sale is paid once.
        if ($charge instanceof MerchantCharge && $charge->settlement_state !== SettlementState::Pending) {
            return true;
        }

        // Withheld payouts wait on the sale, where the release of withheld shares finds them, and the hold
        // stays `ReleasePending` as it does after a failed transfer. Only where the sale's row exists: it is
        // the row that says why the share waits and that is moved when the reason ends.
        $withheld = $charge instanceof MerchantCharge
            ? $this->payouts?->withheldBecause($merchant, $charge->created_at instanceof DateTimeInterface ? CarbonImmutable::instance($charge->created_at) : CarbonImmutable::now())
            : null;

        if ($charge instanceof MerchantCharge && $withheld !== null) {
            $this->ledger?->recordWithholding($charge, $withheld);

            return false;
        }

        // An earlier release may have reached the provider and lost its answer. Its transfer is taken where the
        // provider still has it, rather than made a second time under a key the provider may have dropped.
        if ($charge instanceof MerchantCharge && $this->ledger instanceof RoutedChargeLedger) {
            try {
                $earlier = $this->ledger->earlierTransfer($charge, $this->transfers, $destination);
            } catch (Throwable $failure) {
                $this->ledger->recordTransferFailure($charge, $merchant, $failure);

                throw $failure;
            }

            if ($earlier instanceof TransferResult) {
                $this->ledger->settle($charge, $earlier->reference, $earlier->moved);

                return true;
            }
        }

        // Written before the provider is asked. A run that stops before the answer, a killed worker or a deploy,
        // reaches neither the `catch` below nor the settlement, and this time is then the only trace that lets the
        // doctor count the sale and the retry move it.
        if ($charge instanceof MerchantCharge) {
            $this->ledger?->recordTransferRequested($charge);
        }

        try {
            $moved = $this->transfers->transferShare(
                $destination,
                Money::of($hold->seller_net_minor, $hold->currency),
                $hold->charge_reference,
                $charge instanceof MerchantCharge ? $charge->transferIdempotencyKey() : "billing_protection_hold_{$hold->id}",
            );
        } catch (Throwable $failure) {
            // Written onto the sale as well, where `billing:doctor` counts it and the retry moves it under the
            // same key. The hold stays `ReleasePending` and the exception travels, as before.
            if ($charge instanceof MerchantCharge) {
                $this->ledger?->recordTransferFailure($charge, $merchant, $failure);
            }

            throw $failure;
        }

        // The sale settles with what the provider says it moved, the same way the immediate lane settles it.
        if ($charge instanceof MerchantCharge) {
            $this->ledger?->settle($charge, $moved->reference, $moved->moved);
        }

        return true;
    }

    /** Announce an outcome, where anything is listening. */
    private function announce(object $event): void
    {
        $this->events?->dispatch($event);
    }

    private function settleAsRefund(BuyerProtectionHold $hold, Money $refund): ?BuyerProtectionHold
    {
        $hold = $this->decideUnderLock($hold, static function (BuyerProtectionHold $locked) use ($refund): void {
            $locked->state = BuyerProtectionState::Refunded;
            $locked->buyer_refund_minor = $refund->minorUnits;
            // What the buyer did not get back is what the platform and the seller keep between them, and the fee
            // is the platform's part of it. A refund that left the fee standing against a smaller remainder would
            // make the three figures stop summing to the charge.
            $locked->platform_fee_minor = min($locked->platform_fee_minor, $locked->charge_minor - $refund->minorUnits);
            $locked->seller_net_minor = $locked->charge_minor - $refund->minorUnits - $locked->platform_fee_minor;
            $locked->settled_at = $locked->freshTimestamp();
        });

        if (! $hold instanceof BuyerProtectionHold) {
            return null;
        }

        $this->announce(new BuyerProtectionHoldRefunded(
            $hold->charge_reference,
            $hold->merchant_type,
            $hold->merchant_id,
            $refund,
            $hold->state,
        ));

        return $hold;
    }

    private function markResolutionRequired(BuyerProtectionHold $hold): ?BuyerProtectionHold
    {
        $hold = $this->decideUnderLock($hold, static function (BuyerProtectionHold $locked): void {
            $locked->state = BuyerProtectionState::ResolutionRequired;
        });

        if (! $hold instanceof BuyerProtectionHold) {
            return null;
        }

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
     * Asked three times, because the two failures it catches are both about money taken from a buyer and a
     * configuration can change after boot: at boot, through the go-live checklist, while the marketplace is on;
     * before a sale whose share is to wait under protection charges the buyer; and where a hold is created.
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
