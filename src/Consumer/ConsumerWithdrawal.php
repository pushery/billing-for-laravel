<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ContentOwnership\AccessRevocations;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\Exceptions\WithdrawalWindowClosed;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\ValueObjects\FeeLine;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\WithdrawalSettlement;

/**
 * A buyer exercises their right of withdrawal, and the money is settled the way the law says.
 *
 * ## The caller the formula never had
 *
 * `ConsumerWithdrawalPolicy::valueForUse()` has been implemented and tested since the withdrawal profile
 * landed, and nothing in the package asked it. Measured 2026-08-06: no caller outside `src/Consumer/` and
 * `src/Contracts/`. A subscription withdrawal therefore refunded the full amount or nothing at all,
 * depending on what an operator typed into an admin form — and both of those are wrong in opposite
 * directions.
 *
 * Refunding everything makes the platform pay for days it provided. Refunding nothing keeps money for days
 * it did not, and that is the direction where the buyer is in the right.
 *
 * ## Why the refund is a subtraction and not its own calculation
 *
 * The value used is rounded ONCE, and the refund is the difference from what was paid. Rounding both sides
 * independently produces two figures that do not add back to the payment — a cent that belongs to nobody,
 * on every withdrawal, forever. 29.75 over 7 of 30 days is 6.94 retained and 22.81 returned, and those two
 * are the payment.
 *
 * ## Both links of the chain, or the platform pays the difference
 *
 * Returning money to the buyer is half the act. On a routed sale the creator was already settled for the
 * whole period, and a refund that corrects only the buyer's receipt leaves the self-billed invoice saying
 * the creator earned all of it — so the creator keeps a share of days nobody received and the platform
 * covers the gap out of its own margin. The two documents each add up on their own, which is precisely why
 * nothing catches it.
 *
 * The correction is not computed here. {@see RoutedRefundCorrector} already does all three parts — the
 * arithmetic over both links, the documents a correction has to be, and reading every input off the FROZEN
 * sale rather than off today — and it is the same path a lost chargeback and a canceled prepaid term take.
 * A second correction path beside it would be two places deciding document issuance and period assignment,
 * and the drift between them would be a document that adds up and states the wrong period.
 *
 * ## It refuses rather than guessing
 *
 * A withdrawal type that carries no pro-rata charge (a download whose right extinguished on delivery, a
 * plain refund window) yields a zero value from the policy, so the whole payment goes back. That is the
 * policy's answer rather than this class's, and the distinction matters: the jurisdiction decides which
 * kinds are part-billable, and a consumer under another profile gets a different answer from the same code.
 */
final readonly class ConsumerWithdrawal
{
    public function __construct(
        private WithdrawalGate $gate,
        private BillingAdmin $admin,
        private BillingManager $manager,
        /**
         * Where a routed sale is looked up, so the correction knows whether there is a chain at all.
         *
         * Defaulted for the same reason `BillingAdmin` defaults its own: this class is public surface a
         * consumer may construct, and a single-seller install resolves the ledger, finds nothing, and
         * behaves exactly as it did.
         */
        private RoutedChargeLedger $routed = new RoutedChargeLedger,
        /**
         * The ownership register and the configuration that gates it.
         *
         * Both defaulted for the same reason the ledger above is: this class is public surface a consumer
         * may construct, and an installation that never turned the register on resolves them, reads a
         * switch that is off, and behaves exactly as it did.
         */
        private ?AccessRevocations $revocations = null,
        private ?Repository $config = null,
    ) {}

    /**
     * What a withdrawal on this day comes to — without moving any money.
     *
     * Separate from {@see self::withdraw()} because a buyer is shown the figure before they confirm, and a
     * screen that had to perform the withdrawal in order to display what it costs would be a screen nobody
     * could build.
     *
     * @param  int  $elapsedDays  days of the period already provided when the withdrawal was declared
     * @param  int  $periodDays  the whole period the payment covered
     */
    public function settlementFor(
        WithdrawalType $type,
        Money $periodGross,
        int $elapsedDays,
        int $periodDays,
    ): WithdrawalSettlement {
        $retained = $this->gate->valueForUse($type, $periodGross, $elapsedDays, $periodDays);

        return new WithdrawalSettlement($periodGross, $retained, $periodGross->minus($retained));
    }

    /**
     * Settle the withdrawal: return the difference, keep what was used, correct the chain, and say which it was.
     *
     * The refund is recorded as {@see RefundKind::StatutoryWithdrawal} rather than as goodwill, because the
     * two are different events in the books and only a category can be counted. The reason stays free text
     * for whatever this particular case needs saying about it.
     */
    public function withdraw(
        Model $owner,
        string $chargeReference,
        WithdrawalType $type,
        Money $periodGross,
        int $elapsedDays,
        int $periodDays,
        ?string $reason = null,
        ?Model $actor = null,
    ): WithdrawalSettlement {
        $this->assertWindowIsOpen($chargeReference);

        $settlement = $this->settlementFor($type, $periodGross, $elapsedDays, $periodDays);

        // Nothing to return is not an error and not a refund. A buyer who withdraws on the last day of a
        // period they used in full owes nothing back, and issuing a zero refund would put a money movement
        // in the record where none happened — and a correcting document for nothing is still a document,
        // carrying a number out of a gapless series that somebody then has to explain.
        $refundMoved = true;

        if ($settlement->refundable->minorUnits > 0) {
            $result = $this->admin->refund(
                $owner,
                $chargeReference,
                $settlement->refundable,
                $reason,
                actor: $actor,
                kind: RefundKind::StatutoryWithdrawal,
            );

            // Only what actually moved gets corrected, and only after it moved. A provider that refused the
            // refund leaves the sale exactly as it was; issuing the credit notes anyway would produce a
            // chain that says money went back while the buyer is still out of pocket, and that document is
            // far harder to unwind than the refund is to retry.
            $refundMoved = $result->successful;

            if ($result->successful) {
                // The chain correction used to happen HERE, right after `admin->refund()`. It now happens
                // inside that call, because the support-refund verb had no correction at all and three of
                // the four refund paths did — so a routed sale refunded by support kept documents claiming
                // the full amount. Correcting in both places would write a second document per leg out of a
                // gapless series, for one event.
            }
        }

        $this->returnBuyerFee($owner, $chargeReference, $type, $reason, $actor);

        $this->endOwnership($chargeReference, $refundMoved);

        return $settlement;
    }

    /**
     * Give back the mediation fee, because a fee shares the fate of the transaction it mediated.
     *
     * ## Its own position, and not for tidiness
     *
     * The fee is a second supply between a different pair of parties: the platform mediated FOR THE BUYER,
     * with its own rate and its own place of supply. Adding it to the pro-rata refund would move the same
     * total in one line, and that line could not be documented — there is no single rate that describes
     * 22.81 of a creator's supply plus 5.00 of the platform's own, and no reader could find the fee again.
     *
     * ## Where the money comes from, and why the sale's path would take it from the wrong party
     *
     * The fee was added to the platform's application fee, never to the creator's transfer. So it goes back
     * out of the platform's share alone — {@see BillingAdmin::refundPlatformSupply()} — and the chain is
     * NOT corrected for it. A creator's settlement document says what the creator earned; the fee is not in
     * it, and issuing a correction for money that never reached them would state a reduction of nothing.
     *
     * ## The one kind that keeps it, and it is deliberate
     *
     * `NotApplicable` is C2C between private parties: no consumer contract, so no right to withdraw and
     * nothing that undoes the mediated sale. The mediation was still performed and is still a service the
     * platform is owed for. That case has an assertion of its own rather than a comment, because it is the
     * one an ordinary reading of "fees share the fate of the transaction" gets backwards.
     */
    private function returnBuyerFee(
        Model $owner,
        string $chargeReference,
        WithdrawalType $type,
        ?string $reason,
        ?Model $actor,
    ): void {
        if ($type === WithdrawalType::NotApplicable) {
            return;
        }

        $charge = $this->routed->find($this->manager->driver()->name(), $chargeReference);

        if (! $charge instanceof MerchantCharge || ! $charge->buyerFee() instanceof FeeLine) {
            return;
        }

        // What is LEFT of it, read off the row. A withdrawal gets retried — a provider timeout, an operator
        // clicking twice — and a second full return would be five euros that only ever went out once.
        $outstanding = new Money($charge->buyerFeeRefundableMinor(), $charge->currency);

        if ($outstanding->minorUnits <= 0) {
            return;
        }

        $result = $this->admin->refundPlatformSupply(
            $owner,
            $chargeReference,
            $outstanding,
            RefundKind::WithdrawnBuyerFee,
            $reason,
            $actor,
        );

        // Counted only once the provider says it moved. Counting the intent would make a refused refund look
        // returned, and the retry that should follow it would then find nothing outstanding — which is the
        // failure mode where the buyer is out of pocket and the record says otherwise.
        if ($result->successful) {
            $this->routed->recordBuyerFeeRefund($charge, $result->amount);
        }
    }

    /**
     * Refuse to record a statutory withdrawal after the buyer's window has closed.
     *
     * ## It refuses the CLASSIFICATION, never the money
     *
     * A platform may refund out of goodwill whenever it likes, and that path is untouched. What must not
     * happen is the two being booked as one event: `RefundKind::StatutoryWithdrawal` says the buyer
     * exercised a RIGHT, and after the window that is false. Same money, different event — and telling
     * them apart is the entire reason the kind exists.
     *
     * ## Checked BEFORE anything moves
     *
     * A refusal after the refund would be the worst of both: money gone and the record saying it never
     * happened. So this sits at the top of the method, ahead of the settlement.
     *
     * ## Null and missing both pass, and each for its own reason
     *
     * Most sales have no grant at all — a subscription is not a content purchase — and turning "nothing to
     * compare against" into "too late" would refuse the ordinary case rather than the exotic one.
     *
     * A grant whose window is null is the more interesting pass. Null means no honest date exists, and one
     * of the four ways to get there is a right that EXTINGUISHED on delivery: refusing on it would say "too
     * late" about a sale where the right ended immediately — a true sentence with the wrong reason on it.
     * Whether that sale should be a statutory withdrawal at all is a question about the TYPE, and it is
     * answered where the type is.
     *
     * @throws WithdrawalWindowClosed
     */
    /**
     * End the ownership rows this purchase created, with the reason the buyer actually had.
     *
     * ## The trail that could not tell two things apart
     *
     * `RevokeReason::Withdrawal` had no producer anywhere in `src/`. A statutory withdrawal reached the
     * register only through the refund that followed it, stamped `refund` by the webhook effect — and
     * because a revocation keeps its FIRST reason, `withdrawal` could not be written afterwards. A later
     * correction was a no-op that did not fail.
     *
     * So every exercise of a statutory right looked, in an operator's data, exactly like a goodwill refund
     * they had chosen to give. Two places in the package promise that distinction out loud, and on the
     * shipped path it was not obtainable.
     *
     * Ordering does the rest of the work: revoking here means the later `AddonRefunded` delivery finds a row
     * already revoked and leaves it alone, so `withdrawal` survives without anybody coordinating the two.
     *
     * ## Why a failed refund revokes nothing
     *
     * The same rule the correcting documents follow one method up: only what actually moved is acted on. A
     * provider that refused the refund leaves the buyer out of pocket, and taking their access away on top of
     * that is the one outcome worse than doing nothing. A withdrawal with NOTHING refundable — a period used
     * in full — does revoke: the buyer exercised the right, and no money was owed back to move.
     *
     * ## The same two switches, and no third
     *
     * Gated by exactly what the refund effect reads. A withdrawal that ended access on an installation where
     * a refund would not have would be a second, undocumented policy — decided here, by omission.
     */
    private function endOwnership(string $chargeReference, bool $refundMoved): void
    {
        if (! $refundMoved) {
            return;
        }

        // Resolved here rather than in the constructor: the class is readonly, and a consumer constructing
        // it by hand supplies neither. Reading them at the point of use keeps that construction working and
        // keeps an installation with the register switched off from resolving anything it does not need.
        $config = $this->config ?? Container::getInstance()->make(Repository::class);

        if ($config->get('billing.content_ownership.enabled') !== true) {
            return;
        }

        if ($config->get('billing.content_ownership.revoke_on_refund') !== true) {
            return;
        }

        $revocations = $this->revocations ?? Container::getInstance()->make(AccessRevocations::class);

        // The CHECKOUT reference, and that is measured rather than inferred from the name: the grant is
        // written with it as `source_reference` (`GrantPurchasedContent` passes the session reference), and
        // `assertWindowIsOpen()` above finds the row by matching that same column. `revokeForPayment()` is
        // the other convention in this tree and would resolve a PAYMENT id — it would find nothing here, and
        // silently: an empty array, no error.
        $revocations->revokePurchase($chargeReference, RevokeReason::Withdrawal);
    }

    private function assertWindowIsOpen(string $chargeReference): void
    {
        $window = AccessGrant::query()
            ->where('source_reference', $chargeReference)
            ->orderByDesc('id')
            ->first()
            ?->withdrawal_window_ends_at;

        if (! $window instanceof CarbonInterface) {
            return;
        }

        // Frozen on the row rather than recomputed, which is the whole point of storing it: an operator who
        // shortens the profile's window tomorrow must not shorten a right somebody already holds.
        //
        // Inclusive of the last day. Fourteen days means the fourteenth counts, and an exclusive comparison
        // would refuse a buyer who was in time on the one day it matters most.
        if (CarbonImmutable::now()->greaterThan($window)) {
            throw new WithdrawalWindowClosed($chargeReference, $window);
        }
    }
}
