<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Events\MerchantTransferReversed;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * The record of what was routed, and the only writer of the three reversal totals.
 *
 * Every total here is a CAP, and each is read by a different reversal. The refunded total limits what the
 * buyer can still get back; the reversed total limits what can still be clawed back from the merchant.
 * They are the same number only while the platform returns its commission on every refund — and they part
 * company permanently the first time it does not.
 *
 * Advancing a total is done under a row lock and never as a read in one statement and a write in another.
 * Two refunds arriving together would otherwise both read the same starting figure and both be allowed,
 * and the pair would exceed what was ever paid. That lock compiles away on SQLite, so the concurrency
 * proof belongs on a real engine — a fast in-memory test of it would pass while proving nothing.
 */
final readonly class RoutedChargeLedger
{
    /**
     * @param  ?Dispatcher  $events  resolved from the container when absent, so the many call sites that
     *                               build this ledger directly keep working and still reach a faked
     *                               dispatcher in a test
     */
    public function __construct(private ?Dispatcher $events = null) {}

    /**
     * Record a routed payment, or find the one already recorded for this charge.
     *
     * Idempotent on the provider's charge reference because a webhook redelivery must converge on the row
     * it already wrote. Re-recording would give one payment two rows, and the second would carry all the
     * reversal totals back at zero.
     */
    public function record(
        Model $merchant,
        string $provider,
        string $chargeReference,
        Money $gross,
        Money $fee,
        Money $net,
        /**
         * The commission terms this sale was made under, frozen onto the row.
         *
         * Optional because the three amounts alone already settle a FULL clawback — everything still held
         * goes back, and no terms are needed for that. They are needed for a PARTIAL one, where a
         * proportional share is the wrong figure whenever the fee has a flat part, and where the only other
         * source would be today's configuration: a platform that raised its rate would then claw old sales
         * back at the new one, with nothing on the row to say so.
         */
        ?PlatformFee $policy = null,
        /**
         * WHICH LANE the money took, frozen for the same reason the terms above are.
         *
         * The two reverse in completely different ways: a destination charge unwinds its transfer with the
         * refund, a separate transfer needs a second call with a calculated amount. A refund that read the
         * lane off today's configuration would reverse an old sale as though it had been made under the
         * current one -- and both directions of that mistake are silent. Read as a separate transfer, a
         * destination charge reverses nothing and the merchant keeps a share of a refunded sale; read the
         * other way, the refund carries a flag that does nothing and the failure looks like success.
         *
         * Optional, and null means "written before this was recorded" rather than a guess.
         */
        ?ChargeType $chargeType = null,
        /**
         * WHICH AMOUNT the commission was taken on, expressed as the tax rate that separates it from the
         * payment.
         *
         * The take rate is a NET rate. Frozen for the same reason the terms above are, with one extra edge:
         * a partial clawback recomputes the commission on what REMAINS of the sale, so it needs the rate
         * itself and not the base amount — a stored base cannot answer for a remainder.
         *
         * Optional, and null means the commission was taken on the payment itself. That is what every row
         * written before this basis was corrected actually did, so null is a description of those rows and
         * not a gap in them. A new row always states its rate, including 0.
         */
        ?int $commissionTaxBps = null,
        /**
         * The buyer fee this sale carried, frozen onto the row — or null when it carried none.
         *
         * FROZEN, and that is the whole reason it is stored rather than recomputed. A withdrawal returns the
         * fee that was CHARGED, and recomputing it later would price an old sale at today's rate, today's
         * model and today's place of supply — three settings an operator may change without leaving any
         * trace that they did. The number would come out plausible, which is what makes it the dangerous
         * kind of wrong.
         *
         * Null is the shipped default and the overwhelming majority of rows; it means no such supply
         * happened, which is a different statement from a fee of nought.
         */
        ?FeeLine $buyerFee = null,
    ): MerchantCharge {
        return MerchantCharge::query()->firstOrCreate(
            ['provider' => $provider, 'charge_reference' => $chargeReference],
            [
                'merchant_type' => $merchant->getMorphClass(),
                'merchant_id' => $merchant->getKey(),
                'gross_minor' => $gross->minorUnits,
                'fee_minor' => $fee->minorUnits,
                'charge_type' => $chargeType,
                'fee_bps' => $policy?->bps,
                'fee_flat_minor' => $policy?->flatMinor,
                // The DIRECTION, frozen beside the rate and the flat part, because a clawback is a
                // difference against this sale and not a fresh split of it. The document side has always
                // frozen this; the money side read today's configuration, so a charge priced before somebody
                // changed the setting was reconstructed the other way round from the document correcting it.
                'fee_residual' => $policy?->residual,
                'commission_tax_bps' => $commissionTaxBps,
                'net_minor' => $net->minorUnits,
                'currency' => $gross->currency,
                // All four parts together. A gross with no place would be a taxable supply whose rate cannot
                // be stated, and the reader treats a partial line as no line at all rather than guessing.
                'buyer_fee_gross_minor' => $buyerFee?->gross->minorUnits,
                'buyer_fee_net_minor' => $buyerFee?->net->minorUnits,
                'buyer_fee_tax_minor' => $buyerFee?->tax->minorUnits,
                'buyer_fee_place_of_supply' => $buyerFee?->placeOfSupply,
            ],
        );
    }

    /**
     * Move a charge to settled, once.
     *
     * The transfer reference arrives with settlement rather than with the charge — until the money has
     * actually moved there is nothing to name. A second settlement notice changes nothing: the first one
     * already established when the merchant's share became real.
     */
    public function settle(MerchantCharge $charge, ?string $transferReference = null, ?Money $actuallyMoved = null): bool
    {
        // UNDER THE LOCK, like every other advance of this column — which this one was not.
        //
        // The check and the write used to sit outside any transaction, so two deliveries arriving together
        // both read `pending` and both wrote. Both then returned true, and a caller that treats that as
        // "I made this transition" fires whatever it fires twice.
        //
        // The dangerous pair is not two settlements: it is a settlement racing a failure. Whichever writes
        // last wins, and if that is the failure, a charge whose money really did move is recorded as one
        // that never completed — which every reader of this table then believes, including the ones that
        // decide what a merchant may be refunded.
        //
        // The class docblock has claimed a row lock covers every advance since it was written. It covered
        // exactly one of them.
        return $this->advanceFromPending($charge, fn (MerchantCharge $locked): array => [
            'settlement_state' => SettlementState::Settled,
            'transfer_reference' => $transferReference ?? $locked->transfer_reference,
            // What the provider says it moved, where it said anything. Kept beside what was owed rather
            // than replacing it: the two answer different questions, and a reconciliation needs both.
            'transfer_moved_minor' => $actuallyMoved instanceof Money
                ? $actuallyMoved->minorUnits
                : $locked->transfer_moved_minor,
            'settled_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark a charge as one that will not complete.
     *
     * Only from pending. A settled charge that later goes wrong is a refund or a dispute, not a failure —
     * treating it as one would erase the fact that the money was, for a while, really there.
     */
    public function fail(MerchantCharge $charge): bool
    {
        return $this->advanceFromPending($charge, static fn (): array => [
            'settlement_state' => SettlementState::Failed,
        ]);
    }

    /**
     * Move a charge out of `pending`, once, with the row held.
     *
     * The transition is decided from the row read INSIDE the lock, never from the instance the caller
     * happened to be holding — that instance was loaded before the other delivery arrived, and deciding
     * from it is the whole race.
     *
     * Returns false when somebody else got there first. That is not a failure: it is the honest answer to
     * "did I make this transition", and it lets a caller tell the difference between advancing a charge and
     * merely arriving after one was advanced.
     *
     * @param  callable(MerchantCharge): array<string, mixed>  $changes
     */
    private function advanceFromPending(MerchantCharge $charge, callable $changes): bool
    {
        return (bool) DB::transaction(function () use ($charge, $changes): bool {
            $locked = MerchantCharge::query()
                ->whereKey($charge->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MerchantCharge || $locked->settlement_state !== SettlementState::Pending) {
                return false;
            }

            $locked->forceFill($changes($locked))->save();

            // The caller's instance is refreshed too. It is the object they will read next, and leaving it
            // stale would mean the method that just advanced the charge hands back something that says it
            // did not.
            $charge->forceFill($locked->getAttributes())->syncOriginal();

            return true;
        });
    }

    /**
     * Record the intent to reverse money, before anything is sent.
     *
     * The row is written first and its id becomes the provider's idempotency key, so a retry of the same
     * intent reaches the provider with the same key and is collapsed there. Deriving the key from a
     * recomputed local total instead is what turns a timeout into a second refund.
     *
     * All three amounts are recorded, not one and two derivations. The buyer's refund, what comes back
     * from the merchant and what the platform returns of its own commission are NOT proportional to each
     * other — a fee with a fixed component makes the merchant's share of a half refund more than half of
     * their payout — and a figure recomputed at reversal time would use whatever the fee policy says then
     * rather than what it said when the refund was decided.
     */
    public function beginRefund(
        MerchantCharge $charge,
        Money $amount,
        Money $transferReversal,
        ?Money $feeRefund = null,
        ReversalCause $cause = ReversalCause::Refund,
        ?Money $disputeFee = null,
    ): RefundAttempt {
        $fee = $feeRefund ?? new Money(0, $amount->currency);

        return DB::transaction(function () use ($charge, $amount, $transferReversal, $fee, $cause, $disputeFee): RefundAttempt {
            $attempt = RefundAttempt::query()->create([
                'provider' => $charge->provider,
                'charge_reference' => $charge->charge_reference,
                'amount_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'transfer_reversal_minor' => $transferReversal->minorUnits,
                'fee_refund_minor' => $fee->minorUnits,
                'cause' => $cause,
                // Null, not zero, when no dispute happened — the two are different claims and the
                // schema keeps them apart deliberately.
                'dispute_fee_minor' => $disputeFee?->minorUnits,
                // Placeholder until the id exists; replaced in the same transaction below.
                'idempotency_key' => 'pending:'.bin2hex(random_bytes(8)),
            ]);

            $attempt->forceFill(['idempotency_key' => RefundAttempt::keyFor($attempt->id)])->save();

            return $attempt;
        });
    }

    /**
     * Apply a completed reversal to the totals it caps, each capped independently.
     *
     * Capping rather than trusting the caller is the point: a provider can confirm a refund the platform
     * asked for twice, and the second confirmation must move nothing. Each total is clamped against its
     * own ceiling — the buyer's payment for the refund, the merchant's share for the clawback — so a
     * policy that returns less to the platform than to the buyer stays consistent forever after.
     *
     * ## Why the announcement is here and not at the call site
     *
     * This is the only place that knows what a reversal ACTUALLY moved. The caller knows what it asked
     * for; the caps above decide what it got, and on a redelivered confirmation those differ by the whole
     * amount. A consumer reversing its own payout entry has no way to derive that figure — which is what
     * `MerchantTransferReversed` exists to hand it.
     *
     * It is dispatched AFTER the transaction, and only when something actually moved. Both halves matter:
     * inside the transaction a queued listener can run against a snapshot that has not committed, and a
     * second confirmation that moved nothing would tell a consumer to reverse its ledger a second time —
     * the exact double-reversal the caps above exist to prevent, reintroduced one layer higher.
     *
     * @return array{refunded: int, reversed: int, fee: int} what each total actually moved by
     */
    public function completeRefund(RefundAttempt $attempt, ?Money $actuallyReversed = null): array
    {
        /** @var array{0: ?MerchantCharge, 1: array{refunded: int, reversed: int, fee: int}} $result */
        $result = DB::transaction(function () use ($attempt, $actuallyReversed): array {
            $nothing = ['refunded' => 0, 'reversed' => 0, 'fee' => 0];

            $charge = MerchantCharge::query()
                ->where('provider', $attempt->provider)
                ->where('charge_reference', $attempt->charge_reference)
                ->lockForUpdate()
                ->first();

            if (! $charge instanceof MerchantCharge) {
                return [null, $nothing];
            }

            if ($attempt->status === RefundAttemptStatus::Succeeded) {
                return [null, $nothing];
            }

            $refunded = min($attempt->amount_minor, $charge->refundableMinor());

            // Each amount is capped against its OWN ceiling, read fresh under the lock. The caps are a
            // backstop against a confirmation arriving twice, not the calculation: what comes back from
            // the merchant was decided when the refund was, and is carried on the attempt.
            // What the attempt INTENDED, unless the caller learned what the provider actually did. Those are
            // the same number on the lane that reverses as part of the refund, and they are 0 and "the whole
            // share" on the lane that does not: a separate transfer moved in its own call, so refunding the
            // payment leaves it untouched and the rails deliberately report no reversal reference.
            //
            // Booking the intent there spends reversibleMinor() on a reversal nobody made and announces a
            // clawback to a consumer whose merchant is still holding the money. So a caller that KNOWS may
            // say so, and the default stays the attempt's own figure for the chargeback path, where the
            // reversal was performed by the job that then books it.
            $intended = $actuallyReversed instanceof Money
                ? min($attempt->transfer_reversal_minor, $actuallyReversed->minorUnits)
                : $attempt->transfer_reversal_minor;

            $reversed = min($intended, $charge->reversibleMinor());

            $feeRefunded = min(
                $attempt->fee_refund_minor,
                max(0, $charge->fee_minor - $charge->fee_refunded_minor),
            );

            $charge->forceFill([
                'refunded_minor' => $charge->refunded_minor + $refunded,
                'transfer_reversed_minor' => $charge->transfer_reversed_minor + $reversed,
                'fee_refunded_minor' => $charge->fee_refunded_minor + $feeRefunded,
            ])->save();

            // How much less came back than was asked for, written down rather than computed and dropped.
            // Without it a charge that came back short is indistinguishable from one that came back whole,
            // so nothing downstream can aim a top-up at the difference.
            //
            // Null is the starting value because it is the meaningful one: "nobody compared". It is a
            // different claim from "compared, nothing missing", and a reader acts on the two differently.
            $shortfall = null;

            if ($actuallyReversed instanceof Money) {
                $shortfall = max(0, $attempt->transfer_reversal_minor - $actuallyReversed->minorUnits);
            }

            $attempt->forceFill([
                'status' => RefundAttemptStatus::Succeeded,
                'completed_at' => Carbon::now(),
                'transfer_reversal_short_minor' => $shortfall,
            ])->save();

            return [$charge, ['refunded' => $refunded, 'reversed' => $reversed, 'fee' => $feeRefunded]];
        });

        [$charge, $moved] = $result;

        if ($charge instanceof MerchantCharge && ($moved['reversed'] > 0 || $moved['fee'] > 0)) {
            $this->announceReversal($attempt, $charge, $moved['reversed'], $moved['fee']);
        }

        return $moved;
    }

    /**
     * Tell a consumer what came back, in the amounts that actually moved.
     *
     * The merchant is read through the charge rather than carried on the attempt, because the attempt is
     * about money and the recipient is a fact about the charge. A charge whose merchant has since been
     * deleted announces nothing: an event whose subject is gone cannot be acted on, and dispatching it
     * with a null merchant would push that problem into every listener instead of stopping it here.
     *
     * ## Why the read cannot be allowed to throw
     *
     * A stored `merchant_type` is a class NAME, and a consumer that renames or removes a model leaves rows
     * pointing at a class that no longer exists — where `morphTo` raises an `Error` rather than answering
     * null. By the time this runs the money has already moved and committed. Letting that escape would
     * report a completed reversal as a failure, and the caller's natural response is to retry: the caps
     * make the retry a no-op, so the reversal is right, the caller believes it is wrong, and the
     * announcement is lost anyway.
     *
     * So the announcement is the only thing that fails here, never the reversal. Nothing is swallowed
     * quietly beyond that: an unresolvable merchant is a real problem, but it is a problem about the
     * consumer's own model map, and this is not the operation that should surface it.
     */
    private function announceReversal(RefundAttempt $attempt, MerchantCharge $charge, int $reversed, int $feeReturned): void
    {
        if (! $this->merchantClassExists($charge)) {
            return;
        }

        $merchant = $charge->merchant;

        if (! $merchant instanceof Model) {
            return;
        }

        $disputeFee = $attempt->dispute_fee_minor === null
            ? null
            : new Money($attempt->dispute_fee_minor, $charge->currency);

        ($this->events ?? Container::getInstance()->make(Dispatcher::class))->dispatch(new MerchantTransferReversed(
            merchant: $merchant,
            provider: $charge->provider,
            chargeReference: $charge->charge_reference,
            amount: new Money($reversed, $charge->currency),
            feeReturned: new Money($feeReturned, $charge->currency),
            cause: $attempt->cause ?? ReversalCause::Refund,
            disputeFee: $disputeFee,
        ));
    }

    /**
     * Whether the class this charge's merchant is stored as still exists.
     *
     * Asked BEFORE touching the relation, rather than catching what the relation throws. A stored
     * `merchant_type` is a class name (or a morph-map alias for one), and a consumer that renames or
     * removes a model leaves rows naming a class that is gone — where `morphTo` raises an `Error`, not a
     * null. Asking first states the condition in the open; catching would bury the same rule in an
     * exception handler that reads like defensive noise.
     */
    private function merchantClassExists(MerchantCharge $charge): bool
    {
        $stored = $charge->merchant_type;

        // The morph pair on this table is NOT nullable — erasure marks `merchant_erased_at` and leaves the
        // pair standing, because the financial record outlives the person. So the only way this fails is a
        // class that has gone away, never an absent one.
        return class_exists(Relation::getMorphedModel($stored) ?? $stored);
    }

    /** Record that the provider refused an attempt, so a later reading knows it was tried. */
    /**
     * Count a buyer fee that has gone back, and answer how much of it actually did.
     *
     * ## Why the fee has a counter of its own
     *
     * `refunded_minor` is capped against the sale's gross, and the buyer fee was never part of that gross —
     * it rode on top of the item price and went to the platform's application fee, not into the merchant's
     * transfer. Sharing one counter would break in both directions at once: a fully refunded sale would have
     * no headroom left to return the fee, and a sale whose fee came back would read as over-refunded.
     *
     * ## Under a lock, and capped, because a withdrawal gets retried
     *
     * A provider timeout or an operator clicking twice is the ordinary case. The cap is what makes the
     * second run a no-op instead of a second five euros, and it is read fresh under the row lock rather than
     * from the instance the caller happens to be holding — which may have been loaded before the first run.
     */
    public function recordBuyerFeeRefund(MerchantCharge $charge, Money $amount): Money
    {
        /** @var int $applied */
        $applied = DB::transaction(function () use ($charge, $amount): int {
            $fresh = MerchantCharge::query()
                ->whereKey($charge->getKey())
                ->lockForUpdate()
                ->first();

            if (! $fresh instanceof MerchantCharge) {
                return 0;
            }

            $applied = min($amount->minorUnits, $fresh->buyerFeeRefundableMinor());

            if ($applied <= 0) {
                return 0;
            }

            $fresh->forceFill(['buyer_fee_refunded_minor' => $fresh->buyer_fee_refunded_minor + $applied])->save();

            return $applied;
        });

        // The caller's instance is refreshed rather than left stale, because it is the same object a caller
        // reads back from to decide whether anything happened.
        //
        // Only when there is still a row behind it. `refresh()` THROWS on a deleted model, so a charge
        // erased between the provider call and this one would turn "nothing was counted" — an honest zero —
        // into an exception on a path where the money has already moved. Surfaced by pushing this branch to
        // coverage, which is the whole reason the floor is where it is.
        if ($applied > 0) {
            $charge->refresh();
        }

        return new Money($applied, $charge->currency);
    }

    public function failRefund(RefundAttempt $attempt, string $reason): void
    {
        $attempt->forceFill([
            'status' => RefundAttemptStatus::Failed,
            'failure_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    /** The routed charge behind a provider reference, or null when the payment was never routed. */
    public function find(string $provider, string $chargeReference): ?MerchantCharge
    {
        return MerchantCharge::query()
            ->where('provider', $provider)
            ->where('charge_reference', $chargeReference)
            ->first();
    }
}
