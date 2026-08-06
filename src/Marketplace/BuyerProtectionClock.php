<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\Exceptions\BuyerProtectionMisconfigured;
use Pushery\Billing\Models\BuyerProtectionHold;
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

    public function __construct(private Repository $config) {}

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
            'state' => BuyerProtectionState::AwaitingConfirmation,
            'confirm_by' => $paidAt->copy()->addDays($this->confirmAfterDays()),
            'decide_by' => $paidAt->copy()->addDays($this->decideAfterDays()),
        ]);
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
        // The seller gets what is left after the platform's own fee. The three figures are written together
        // and always sum to the charge, so no end state can quietly lose or invent a cent.
        $hold->state = BuyerProtectionState::Released;
        $hold->seller_net_minor = $hold->charge_minor - $hold->platform_fee_minor;
        $hold->buyer_refund_minor = 0;
        $hold->settled_at = $hold->freshTimestamp();
        $hold->save();

        return $hold;
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

        return $hold;
    }

    private function markResolutionRequired(BuyerProtectionHold $hold): BuyerProtectionHold
    {
        $hold->state = BuyerProtectionState::ResolutionRequired;
        $hold->save();

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
