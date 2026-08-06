<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\ReviewState;
use Pushery\Billing\Models\SubmittedInvoice;

/**
 * The fallback lane's review: reconcile a creator's submitted invoice against what they actually earned, and
 * hold their payout until it clears.
 *
 * The reconciliation is the whole point. Without it the platform pays out what the creator WRITES — a
 * creator who invoices 300.00 net when the transactions of the period come to 270.00 would be overpaid by
 * 30.00. So the submitted net and tax must match the expected figures within a tolerance (config, default
 * exact); a mismatch is a finding per field, and only a passing review may release the payout.
 *
 * WHERE THAT LOCK IS NOT, and it matters: `holdsPayout()` has no caller in this package, because there is
 * no payout path here to hang it on. This class ANSWERS whether a creator may be paid; nothing in `src/`
 * asks. Until a payout path exists, the answer has to be asked by whoever pays — a consumer that pays out
 * without calling this is not defeating a lock, it is skipping a question nobody put in its way.
 *
 * Stated plainly because the alternative reads like protection. Earlier wording here and in the changelog
 * said the lock "sits at the payout path, so a creator with any open, failed or manual-review submission is
 * skipped", which describes an enforcement that does not exist and would be believed by exactly the reader
 * who most needs to know it is theirs to wire.
 *
 * What produces the expected figures (the transaction aggregate) and what actually pays out (the rails) live
 * elsewhere; this decides whether a submission may be paid. The format parser that fills a submission's
 * amounts is a swappable seam of its own — this reviews the amounts, however they arrived.
 */
final readonly class FallbackLane
{
    public function __construct(private Repository $config) {}

    /**
     * Reconcile a submission against the expected net and tax the creator earned this period, storing the
     * result and the per-field findings on it. Only an exact-or-within-tolerance match passes.
     */
    public function reconcile(SubmittedInvoice $invoice, int $expectedNetMinor, int $expectedTaxMinor): SubmittedInvoice
    {
        $tolerance = $this->config->get('billing.marketplace.fallback.tolerance_minor', 0);
        $tolerance = is_int($tolerance) ? $tolerance : 0;

        $findings = [];

        if (abs($invoice->net_minor - $expectedNetMinor) > $tolerance) {
            $findings['net'] = ['submitted' => $invoice->net_minor, 'expected' => $expectedNetMinor];
        }

        if (abs($invoice->tax_minor - $expectedTaxMinor) > $tolerance) {
            $findings['tax'] = ['submitted' => $invoice->tax_minor, 'expected' => $expectedTaxMinor];
        }

        $invoice->forceFill([
            'review_state' => $findings === [] ? ReviewState::Passed : ReviewState::Failed,
            'findings' => $findings === [] ? null : $findings,
        ])->save();

        return $invoice;
    }

    /**
     * Whether a creator's payout is held because a fallback submission has not cleared.
     *
     * A creator on the fallback lane is paid only once their invoice passes: any submission that is pending,
     * failed or in manual review holds the whole payout. A creator with no submission at all is NOT held here
     * — that is the self-billed path's or a caller's concern; this speaks only to submissions that exist.
     */
    public function holdsPayout(Model $creator): bool
    {
        return SubmittedInvoice::query()
            ->where('owner_type', $creator->getMorphClass())
            ->where('owner_id', $creator->getKey())
            ->where('review_state', '!=', ReviewState::Passed->value)
            ->exists();
    }
}
