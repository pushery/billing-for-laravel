<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Enums\ReversalAttribution;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Exceptions\ReportingCounterDisabled;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * What the PLATFORM kept out of a seller's sales in a window — the third reporting figure.
 *
 * ## A withheld fee is not an event of its own
 *
 * It is a deduction FROM a particular consideration, and the reporting field says so: fees withheld or
 * charged, out of what is paid or credited to the seller. Its quarter is therefore the quarter of the
 * consideration it reduces, and the consideration is dated by the settlement document.
 *
 * That is not how this figure was placed. It lived on {@see MerchantChargeAnnualEarningsCounter}, placed by
 * the charge's own `settled_at`, while the gross inflow beside it in the same return was placed by the
 * document's `issued_at`. On the ordinary sale the two coincide. When they do not — money on 31 March,
 * document on 1 April — the return stated a Q1 fee withheld from a consideration it placed in Q2, and the
 * Q1 pair had nothing for that fee to have been deducted from.
 *
 * Neither figure was implausible on its own, which is why no plausibility rule could catch it.
 *
 * ## Why it is a counter of its own now
 *
 * The method's own docblock argued it belonged beside the section-19 basis because the two shared a window
 * and a replay: *"a second class restating them would be two places for each"*. That was right while the
 * premise held. Placing this figure by the document removes the shared window, and the section-19 basis
 * keeps the money clock because it answers a different duty — what a seller actually received, for a
 * small-business threshold. Two duties with two clocks is correct; one counter with two clocks is not.
 *
 * What genuinely stayed shared did not get copied: {@see ChargeReversals} loads and groups the refunds for
 * both. The attribution expression is written out in each, deliberately, because that is the part a reader
 * has to be able to compare.
 *
 * ## The fallback, and why it is not a quiet one
 *
 * A charge with no settling document has no consideration whose date could place it — a real state, not an
 * edge: the money can move before any document is raised, and a legacy document that names no provider
 * cannot be matched to its charge at all. Such a charge is placed by `settled_at`, which is what the
 * package did everywhere before.
 *
 * It is NOT dropped. A fee nobody reports is as wrong as one reported twice, and it is the quieter of the
 * two. {@see chargesPlacedByTheirMoneyIn()} makes the fallback askable, so the plausibility step can state
 * it instead of a reader discovering it while reconciling a return.
 *
 * ## What a refund does to it
 *
 * The fee comes back in whatever part the policy returned, and the ledger caps each confirmation against
 * what was still refundable at that moment — so a redelivered confirmation is stamped succeeded and moves
 * nothing. The replay reads what MOVED, never what an attempt asked for; reading the request would subtract
 * a fee that was never given back.
 *
 * Floored at zero per charge, because a refund must never raise what the platform is counted as having
 * kept — and because the shipped `retain` policy returns nothing, which is a real outcome and not a gap.
 *
 * ## Not derived from the other two, and that is the point
 *
 * Gross inflow minus payout is NOT the fee. It is right for a single unmixed sale at one rate and wrong for
 * a basket that mixes rates, a sale with a flat fee component, or any period holding both — and it is wrong
 * quietly, because both inputs are correct.
 */
final readonly class WithheldFeeCounter
{
    /**
     * Shaped like its two sibling counters: the configuration, and nothing else.
     *
     * {@see ChargeReversals} is built here rather than injected because it is stateless plumbing and not a
     * seam anybody replaces — and because a counter a consumer cannot construct with `new` while its
     * siblings can is a gratuitous difference between three classes that answer the same kind of question.
     */
    public function __construct(private ?Repository $config = null) {}

    /**
     * What the platform kept out of this party's sales in the window.
     *
     * @throws ReportingCounterDisabled when the installation has switched the counter off
     */
    public function feesWithheldIn(Model $party, string $currency, CountingPeriod $period): Money
    {
        $this->assertEnabled();

        $code = strtoupper($currency);
        $attribution = $this->attribution();

        $start = $period->from->toDateTimeString();
        $end = $period->until->toDateTimeString();

        $charges = $this->chargesTouching($party, $code, $start, $end, $attribution);
        $reversals = new ChargeReversals()->of($charges);

        $total = 0;
        $placedByTheSale = $attribution === ReversalAttribution::OriginalPeriod;

        foreach ($charges as $charge) {
            $placedHere = $this->placedInside($charge, $start, $end);

            if ($placedHere) {
                // `fee_minor` ONLY, and the omission is deliberate. A row can also carry
                // `buyer_fee_*` -- what the BUYER was charged for arranging their side -- and that never
                // touches what reaches the seller, so it can never be a deduction from their
                // consideration. Summing "everything the platform charged on this sale" here is a
                // one-line edit that balances perfectly and over-reports the seller in a return nobody
                // re-derives. `ReportedWithheldFeeByRegimeTest` fails on exactly that edit.
                $total += $charge->fee_minor;
            }

            $mine = $reversals[ChargeReversals::keyOf($charge)] ?? [];

            // The same two readings as the section-19 basis, and deliberately the same expression: a second
            // attribution rule written beside the first is how the two answers start disagreeing about
            // which quarter a refund belongs to, each of them internally consistent.
            $total -= $placedByTheSale
                ? ($placedHere ? $this->feeReturnedInside($charge, $mine, null, null) : 0)
                : $this->feeReturnedInside($charge, $mine, $start, $end);
        }

        return Money::of($total, $code);
    }

    /**
     * The charges this window placed by their MONEY because no document could place them.
     *
     * The fallback, stated rather than assumed. A caller assembling a return asks this and reports it; the
     * figure above is correct either way, but a reader reconciling a quarter against the seller's own
     * settlement documents needs to know which rows were not placed by one.
     *
     * @return Collection<int, MerchantCharge>
     *
     * @throws ReportingCounterDisabled when the installation has switched the counter off
     */
    public function chargesPlacedByTheirMoneyIn(Model $party, string $currency, CountingPeriod $period): Collection
    {
        $this->assertEnabled();

        $code = strtoupper($currency);
        $start = $period->from->toDateTimeString();
        $end = $period->until->toDateTimeString();

        return $this->chargesTouching($party, $code, $start, $end, $this->attribution())
            ->filter(fn (MerchantCharge $charge): bool => $charge->settlement_invoice_id === null
                && $this->settledInside($charge, $start, $end))
            ->values();
    }

    /**
     * The charges that can move this window.
     *
     * Three ways in, and the first is the one that changed: the settlement document that paid this charge
     * out was issued inside the window. The charge's own date is not consulted there — a month-end
     * collective document places a whole month of transactions on its Ultimo, and that is the date the
     * consideration carries.
     *
     * The second is the fallback above. The third is a refund that completed inside the window, dropped
     * under `original_period` because a reversal is then placed by the sale it corrects, so a charge placed
     * elsewhere cannot move this window at all and loading it would only invite a later reader to subtract
     * it.
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
            ->with('settlementDocument')
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $party->getKey())
            ->where('currency', $currency)
            ->where(function (Builder $touching) use ($start, $end, $attribution): void {
                $touching->whereExists(function (QueryBuilder $document) use ($start, $end): void {
                    $document->select('id')
                        ->from('billing_invoices')
                        ->whereColumn('billing_invoices.id', 'billing_merchant_charges.settlement_invoice_id')
                        ->whereNotNull('billing_invoices.issued_at')
                        ->where('billing_invoices.issued_at', '>=', $start)
                        ->where('billing_invoices.issued_at', '<', $end);
                });

                $touching->orWhere(function (Builder $undocumented) use ($start, $end): void {
                    $undocumented->whereNull('settlement_invoice_id')
                        ->where('settlement_state', SettlementState::Settled->value)
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

    /** Whether the consideration this fee was withheld from falls inside the window. */
    private function placedInside(MerchantCharge $charge, string $start, string $end): bool
    {
        $document = $charge->settlementDocument;

        if ($document === null) {
            return $this->settledInside($charge, $start, $end);
        }

        $issuedAt = $document->issued_at;

        return $issuedAt !== null
            && $issuedAt->toDateTimeString() >= $start
            && $issuedAt->toDateTimeString() < $end;
    }

    /** The fallback reading: the charge's own settlement, for a charge no document claims. */
    private function settledInside(MerchantCharge $charge, string $start, string $end): bool
    {
        $settledAt = $charge->settled_at;

        return $charge->settlement_state === SettlementState::Settled
            && $settledAt !== null
            && $settledAt->toDateTimeString() >= $start
            && $settledAt->toDateTimeString() < $end;
    }

    /**
     * How much of a charge's fee came back, replayed the way the ledger caps it.
     *
     * The ceiling is the fee itself, walked down as confirmations are applied. `completeRefund()` caps each
     * confirmation under the lock and then stamps the attempt succeeded regardless, so the row keeps the
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

    /**
     * Refuse outright on an installation that has switched this counter off.
     *
     * A refusal rather than a zero. Zero is a real reporting answer — this seller had nothing withheld —
     * and a disabled counter handing it back would let a platform file a return saying every seller was
     * charged nothing, with nothing red and every figure internally consistent.
     *
     * @throws ReportingCounterDisabled
     */
    private function assertEnabled(): void
    {
        if ($this->config?->get('billing.tax_counters.dac7.enabled', true) === false) {
            throw ReportingCounterDisabled::forWithheldFees();
        }
    }

    /** Which window a reversal reduces — read from the one place the rule lives. */
    private function attribution(): ReversalAttribution
    {
        return ReversalAttribution::configured($this->config);
    }
}
