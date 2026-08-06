<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Pushery\Billing\Contracts\AnnualEarningsCounter;
use Pushery\Billing\Contracts\CountsEarnings;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Enums\ReversalAttribution;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Exceptions\ReportingCounterDisabled;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * The shipped counter: a projection over the routed-charge record and its reversals, so a rebuild always
 * reproduces the running figure. It keeps no tally of its own to drift.
 *
 * ## Which of three numbers this counts
 *
 * One routed sale produces three legitimate figures, and this threshold is measured on exactly one of them.
 * At 119.00 with 19% tax and a 10% commission: the buyer paid 119.00, 109.00 reached the merchant, and
 * 90.00 is what their supply was worth. The section-19 basis is the last — the taxable amount of the
 * merchant's own supply, received.
 *
 * It used to sum `net_minor`, the merchant's whole receipt, and call it the payout net in its own comment.
 * That is about a fifth too high, and the direction is the expensive one: the figure decides when a creator
 * stops being a small business, so counting high flips them out of the regime EARLY and has them owing a
 * tax they do not yet owe, on every settlement, until somebody recomputes it by hand.
 *
 * The tax reaches the merchant because it is theirs to remit. That is precisely why it is not turnover of
 * theirs, and why it has to come off before this counts.
 *
 * ## Why this is a row walk and not two sums
 *
 * Separating the tax needs the rate the sale was made under, which lives on the row and differs between
 * rows. No aggregate can apply a per-row divisor, so the arithmetic happens here — over a query narrowed to
 * the rows that can move this window, not over the party's whole history.
 *
 * The year is a HALF-OPEN moment range ([Jan 1, next Jan 1)) rather than a `YEAR()` call, because the SQL
 * for extracting a year differs across SQLite, PostgreSQL and MySQL and a range compares identically on all
 * three. The cash basis runs in both directions: a settled charge counts in the year it SETTLED, and a
 * reversal subtracts in the year it COMPLETED — which can be a later year than the charge, exactly as the
 * receipts-basis (Ist-Prinzip) requires.
 *
 * The reversal's own row carries no party, so it is attributed through the charge it reverses, matched on the
 * provider AND the charge reference (unique together) — never the reference alone, which is unique only per
 * provider.
 */
final readonly class MerchantChargeAnnualEarningsCounter implements AnnualEarningsCounter, CountsEarnings
{
    /**
     * Optional and defaulted, so a counter built by hand in a script answers the same way the
     * container-resolved one does — and so adding the setting did not become a breaking change for anybody
     * constructing this directly. Absent, the reading falls to what the package has always done.
     */
    public function __construct(private ?Repository $config = null) {}

    /**
     * The calendar-year answer, which is now one window among others.
     *
     * Kept, and delegating rather than duplicating: the annual contract is implemented outside this package,
     * so it stays exactly as it was for anybody who binds their own. What it must not become is a second
     * implementation of the same sum — two tallies over one set of transactions disagree the moment a refund
     * lands in one window and not the other.
     */
    public function earnedIn(Model $party, string $currency, int $year): Money
    {
        return $this->countedIn($party, $currency, CountingPeriod::year($year));
    }

    public function countedIn(Model $party, string $currency, CountingPeriod $period): Money
    {
        $code = strtoupper($currency);
        // WHICH WINDOW A REVERSAL REDUCES, from the one place that rule lives.
        //
        // This counter used to answer it on its own and always the same way, while the reporting counter on
        // the same seam read the setting. So `original_period` moved a reversal for one of them and not for
        // the other — one key, two behaviors, and nothing in the key's name to say which it governed.
        //
        // It matters more here than there. This is the figure that decides whether a creator is still a
        // small business: a December sale that tips them over, refunded in February, either leaves the
        // crossing standing or unwinds the year it happened in. Those are different legal outcomes for the
        // settlements issued in between, which is why the choice is configuration and not a default.
        $attribution = ReversalAttribution::configured($this->config);
        // The window as strings — comparable identically on every engine, where a YEAR() call is not. It is
        // half-open by construction, so a sale at the last instant of December counts in December and a
        // closed range's "last second" problem never arises.
        $start = $period->from->toDateTimeString();
        $end = $period->until->toDateTimeString();

        $charges = $this->chargesTouching($party, $code, $start, $end, $attribution);

        // Every reversal of every one of those charges, in ONE query rather than one per charge. This runs
        // over a whole marketplace on a nightly sweep, so a query per charge is a query per sale per creator
        // per night — and the previous shape was two aggregates, which makes a per-row fan-out a regression
        // rather than a neutral rewrite.
        $reversals = $this->reversalsOf($charges);

        $total = 0;

        $placedByTheSale = $attribution === ReversalAttribution::OriginalPeriod;

        foreach ($charges as $charge) {
            $settledHere = $this->settledInside($charge, $start, $end);

            if ($settledHere) {
                $total += $charge->payoutNet()->minorUnits;
            }

            $mine = $reversals[$this->keyOf($charge)] ?? [];

            // Placed by the sale: every reversal of a charge that settled here reduces THIS window, whenever
            // it happened — and a charge that settled elsewhere contributes nothing, because its reversals
            // belong to its own year. A reversal with no completion moment becomes placeable this way, which
            // is the one thing this reading can do that the other cannot.
            //
            // Placed by itself: the window is the reversal's own, and a charge from any year can reduce it.
            $total -= $placedByTheSale
                ? ($settledHere ? $this->reversedInside($charge, $mine, null, null) : 0)
                : $this->reversedInside($charge, $mine, $start, $end);
        }

        return Money::of($total, $code);
    }

    /**
     * The charges that can move this window: settled inside it, or reversed inside it.
     *
     * Narrowed in SQL rather than filtered afterwards, because a party's whole history is unbounded while
     * the rows that can affect one year are not. A charge settled years ago still belongs here when a refund
     * of it completed inside the window — that is the receipts basis, and it is the case a naive filter on
     * `settled_at` alone would drop.
     *
     * Under `original_period` the second half is dropped: a reversal is then placed by the sale it corrects,
     * so a charge that settled elsewhere cannot move this window at all and loading it would only invite a
     * later reader to subtract it.
     *
     * @return Collection<int, MerchantCharge>
     */
    private function chargesTouching(
        Model $party,
        string $currency,
        string $start,
        string $end,
        ReversalAttribution $attribution,
    ): Collection {
        return MerchantCharge::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $party->getKey())
            ->where('currency', $currency)
            ->where(function (Builder $touching) use ($start, $end, $attribution): void {
                $touching->where(function (Builder $settled) use ($start, $end): void {
                    $settled->where('settlement_state', SettlementState::Settled->value)
                        ->where('settled_at', '>=', $start)
                        ->where('settled_at', '<', $end);
                });

                if ($attribution === ReversalAttribution::OriginalPeriod) {
                    return;
                }

                $touching->orWhereExists(function (QueryBuilder $reversed) use ($start, $end): void {
                    // Matched on the provider AND the reference. The reference alone is unique only per
                    // provider, so a second processor's identical reference would attach its reversals to a
                    // stranger's charge — and there is no relation to lean on here, because the pair is a
                    // composite key rather than a foreign one.
                    $reversed->select('id')
                        ->from('billing_refund_attempts')
                        ->whereColumn('billing_refund_attempts.provider', 'billing_merchant_charges.provider')
                        ->whereColumn(
                            'billing_refund_attempts.charge_reference',
                            'billing_merchant_charges.charge_reference',
                        )
                        ->where('billing_refund_attempts.status', RefundAttemptStatus::Succeeded->value)
                        ->where('billing_refund_attempts.completed_at', '>=', $start)
                        ->where('billing_refund_attempts.completed_at', '<', $end);
                });
            })
            ->get();
    }

    /** Whether the charge itself settled inside the window. */
    private function settledInside(MerchantCharge $charge, string $start, string $end): bool
    {
        $settledAt = $charge->settled_at;

        return $charge->settlement_state === SettlementState::Settled
            && $settledAt !== null
            && $settledAt->toDateTimeString() >= $start
            && $settledAt->toDateTimeString() < $end;
    }

    /**
     * How much section-19 basis this charge lost to reversals inside the window.
     *
     * ## Why a division would be wrong here
     *
     * The stored `transfer_reversal_minor` is a RECEIPT figure — it includes the buyer's tax that came back
     * with it. Turning it into a payout-net figure by dividing it by the tax rate looks right and is not:
     * the commission's flat component is not returned proportionally (the platform performed the handling
     * once, which is the whole reason {@see ClawbackCalculator} exists), and a division would spread it as
     * though it were.
     *
     * What holds instead is an identity that needs no fee terms at all. What a merchant holds on a sale
     * exceeds its payout net by exactly the tax on that sale, so the payout net a reversal removes is the
     * receipt it removes LESS the tax that came back with it — and the tax that came back is the difference
     * between the tax on the sale before and after. The frozen rate answers both.
     *
     * ## Why the reversals are walked in order
     *
     * The tax on "the sale before this refund" depends on how much was already refunded, so each reversal is
     * placed after the ones that completed before it. Ordered by completion and then by id, so two refunds
     * confirmed in the same second still have one definite order rather than whichever the engine returns.
     */
    /**
     * @param  list<RefundAttempt>  $reversals
     * @param  ?string  $start  null when the window is the SALE's rather than the reversal's, in which case
     *                          every succeeded reversal belongs here — including one with no completion
     *                          moment, which has no window of its own to be placed in
     */
    private function reversedInside(MerchantCharge $charge, array $reversals, ?string $start, ?string $end): int
    {
        $gross = $charge->gross();
        $refundedBefore = 0;
        // What the merchant could still have given back at each point, replayed the way the ledger caps it.
        $reversibleLeft = $charge->net_minor;
        $lost = 0;

        foreach ($reversals as $reversal) {
            $before = new Money(max(0, $gross->minorUnits - $refundedBefore), $charge->currency);
            $refundedBefore = min($gross->minorUnits, $refundedBefore + $reversal->amount_minor);
            $after = new Money($gross->minorUnits - $refundedBefore, $charge->currency);

            // What ACTUALLY moved, not what the attempt asked for. `RoutedChargeLedger::completeRefund()`
            // caps each confirmation against the ceiling it reads under the lock and then stamps the attempt
            // succeeded regardless, so the row keeps the requested figure — a second confirmation of the same
            // refund is recorded as succeeded and moves nothing. Reading the request would subtract money
            // that never left the merchant.
            $applied = min($reversal->transfer_reversal_minor, $reversibleLeft);
            $reversibleLeft -= $applied;

            if ($start !== null && $end !== null) {
                $completedAt = $reversal->completed_at?->toDateTimeString();
                if ($completedAt === null) {
                    continue;
                }
                if ($completedAt < $start) {
                    continue;
                }
                if ($completedAt >= $end) {
                    continue;
                }
            }

            $taxReturned = $charge->taxWithin($before)->minus($charge->taxWithin($after));

            // FLOORED AT ZERO, because a refund must never raise what a creator is counted as having earned.
            // The package ships a policy that leaves the merchant's share with them — a refund whose
            // clawback is zero — and against it the subtraction alone comes out NEGATIVE by the tax on the
            // refunded part, so a half-refunded 90.00 sale was counted as 99.50 of turnover. Over-counting
            // on this threshold is the direction that pushes a creator out of the small-business regime
            // early, which is the whole thing the basis correction was for.
            //
            // Zero is also the honest answer there rather than a clamp: the merchant kept what they held, so
            // on a receipts basis nothing came back out of what they received.
            $lost += max(0, $applied - $taxReturned->minorUnits);
        }

        return $lost;
    }

    /**
     * Every succeeded reversal of the given charges, grouped by the charge each belongs to.
     *
     * One query for the whole set. A query per charge is a query per sale per creator on a nightly sweep,
     * and the shape this replaced was two aggregates — so fanning out per row would trade a wrong figure for
     * a slow one rather than fixing anything.
     *
     * Grouped on the provider AND the reference, never the reference alone: it is unique only per provider,
     * so a second processor issuing the same string would hand its reversals to a stranger's sale. The pair
     * is a composite key rather than a foreign one, which is why this is a grouping rather than a relation.
     *
     * Every succeeded reversal is loaded, not only those inside the window: an earlier refund already moved
     * the sale that a later one is measured against, and dropping it would measure the later one against a
     * sale that was not there.
     *
     * @param  Collection<int, MerchantCharge>  $charges
     * @return array<string, list<RefundAttempt>>
     */
    private function reversalsOf(Collection $charges): array
    {
        if ($charges->isEmpty()) {
            return [];
        }

        $grouped = [];

        $attempts = RefundAttempt::query()
            ->whereIn('provider', $charges->pluck('provider')->unique()->all())
            ->whereIn('charge_reference', $charges->pluck('charge_reference')->unique()->all())
            ->where('status', RefundAttemptStatus::Succeeded->value)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        foreach ($attempts as $attempt) {
            // The whereIn pair is a cross product, so a row is kept only when BOTH halves belong to the same
            // charge. Filtering here rather than trusting the query is what keeps the composite key honest.
            $grouped[$attempt->provider.'|'.$attempt->charge_reference][] = $attempt;
        }

        return $grouped;
    }

    /**
     * What the PLATFORM kept out of this party's sales in the window — the third reporting figure.
     *
     * ## Why it lives here rather than in a counter of its own
     *
     * It is a different QUESTION on the same machinery. Which charges can move a window, how a reversal is
     * attributed, and how a confirmation is capped against what was actually left are one rule each, and a
     * second class restating them would be two places for each — the shape this package keeps finding in
     * itself, where both copies are internally consistent and only their disagreement is the defect.
     *
     * So the window and the replay are shared, and the BASIS is what the method name states. Asked for by
     * NAME, exactly like {@see SettlementGrossInflowCounter}: this class's contract method answers the
     * small-business threshold on the merchant's own supply, and this one answers what the platform withheld.
     * The two are both plausible figures about one sale, and being unable to reach this one through a bare
     * type-hint is the safeguard rather than an inconvenience.
     *
     * ## Not derived from the other two, and that is the point
     *
     * Gross inflow minus payout is NOT the fee. It is right for a single unmixed sale at one rate and wrong
     * for a basket that mixes rates, a sale with a flat fee component, or any period holding both — and it is
     * wrong quietly, because both inputs are correct. The fee is counted as its own figure, off the amount
     * that was actually withheld.
     *
     * ## What a refund does to it
     *
     * The fee comes back in whatever part the policy returned, and the ledger caps each confirmation against
     * what was still refundable at that moment — so a redelivered confirmation is stamped succeeded and moves
     * nothing. The replay reads what MOVED, never what an attempt asked for; reading the request would
     * subtract a fee that was never given back.
     *
     * Floored at zero per charge, because a refund must never raise what the platform is counted as having
     * kept — and because the shipped `retain` policy returns nothing, which is a real outcome and not a gap.
     *
     * @throws ReportingCounterDisabled when the installation has switched the counter off
     */
    public function feesWithheldIn(Model $party, string $currency, CountingPeriod $period): Money
    {
        if ($this->config?->get('billing.tax_counters.dac7.enabled', true) === false) {
            throw ReportingCounterDisabled::forWithheldFees();
        }

        $code = strtoupper($currency);
        $attribution = ReversalAttribution::configured($this->config);

        $start = $period->from->toDateTimeString();
        $end = $period->until->toDateTimeString();

        $charges = $this->chargesTouching($party, $code, $start, $end, $attribution);
        $reversals = $this->reversalsOf($charges);

        $total = 0;
        $placedByTheSale = $attribution === ReversalAttribution::OriginalPeriod;

        foreach ($charges as $charge) {
            $settledHere = $this->settledInside($charge, $start, $end);

            if ($settledHere) {
                $total += $charge->fee_minor;
            }

            $mine = $reversals[$this->keyOf($charge)] ?? [];

            // The same two readings as the earnings figure above, and deliberately the same expression: a
            // second attribution rule written beside the first is how the two answers start disagreeing about
            // which quarter a refund belongs to, each of them internally consistent.
            $total -= $placedByTheSale
                ? ($settledHere ? $this->feeReturnedInside($charge, $mine, null, null) : 0)
                : $this->feeReturnedInside($charge, $mine, $start, $end);
        }

        return Money::of($total, $code);
    }

    /**
     * How much of a charge's fee came back, replayed the way the ledger caps it.
     *
     * The ceiling is the fee itself, walked down as confirmations are applied — the same shape
     * {@see reversedInside()} uses for the merchant's share, with the other ceiling. `completeRefund()` caps
     * each confirmation under the lock and then stamps the attempt succeeded regardless, so the row keeps the
     * REQUESTED figure. Reading it would give back a fee that never moved.
     *
     * @param  list<RefundAttempt>  $reversals
     * @param  ?string  $start  null when the window is the SALE's, in which case every succeeded
     *                          confirmation belongs here — including one with no completion moment
     */
    private function feeReturnedInside(MerchantCharge $charge, array $reversals, ?string $start, ?string $end): int
    {
        $feeLeft = $charge->fee_minor;
        $returned = 0;

        foreach ($reversals as $reversal) {
            $applied = min(max(0, $reversal->fee_refund_minor), max(0, $feeLeft));
            $feeLeft -= $applied;

            if ($start !== null && $end !== null) {
                $completedAt = $reversal->completed_at?->toDateTimeString();
                if ($completedAt === null) {
                    continue;
                }
                if ($completedAt < $start) {
                    continue;
                }
                if ($completedAt >= $end) {
                    continue;
                }
            }

            $returned += $applied;
        }

        return $returned;
    }

    /** The composite identity of a charge — provider and reference, because neither is unique alone. */
    private function keyOf(MerchantCharge $charge): string
    {
        return $charge->provider.'|'.$charge->charge_reference;
    }
}
