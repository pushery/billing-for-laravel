<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Exceptions\CancellationUnavailable;
use Pushery\Billing\Invoicing\ProratedTermRefund;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Support\BillingAdmin;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;

/**
 * Canceling a prepaid term: the unused part goes back to the buyer, and the chain is corrected for it.
 *
 * A year paid in January and canceled after four months owes eight months. {@see ProratedTermRefund} knows how
 * much, and {@see BillingAdmin::refund()} moves it: the same rails, the same audit record and, on a routed sale,
 * the same correction of both links of the chain as every other refund. This class asks for the amount and hands
 * it to that verb, and does nothing else with the money or the documents.
 *
 * ## Why it does not correct the chain itself
 *
 * It used to, by calling the corrector directly, while the refund was left to the consumer. A consumer who refunded
 * through the package and then canceled the term got two correcting documents on each side for one refund, because
 * the refund verb corrects the chain on its own; one who only canceled got correcting documents for money that never
 * moved. The refund verb corrects after the provider confirms, with the attempt that moved the money, so the
 * documents and the money cannot part. A refund the provider refuses moves nothing and corrects nothing.
 *
 * ## Why the caller supplies the term, and this class does not look it up
 *
 * Nothing in this package stores "this subscription was sold as a twelve-month prepaid term, four of them used".
 * `ProratedTermRefund` takes those numbers. The tempting substitute, deriving them from a start date and today, is
 * wrong the moment a cycle was shifted, paused or swapped, and wrong silently. The consumer knows what it sold; this
 * class refuses to guess.
 *
 * That is also why this is a service the consumer calls rather than a listener on a cancellation event. A refund is
 * a money movement, not a side effect of a status change: cancellations arrive from a webhook, an admin action and
 * the consumer's own UI, many of them owing nothing back (a cancellation at period end simply runs out), and a
 * listener would have to tell those apart at a point that no longer knows the reason.
 *
 * ## Once per charge
 *
 * The routed ledger caps a refund at what is left of the sale, not at what the term owes, so the same call twice,
 * from a double click or a retried job, would refund the unused part twice. The charge's row is stamped under a lock
 * before the provider is asked, in a transaction of its own that the provider call stays outside of, and a charge
 * that carries the stamp is refused. A refund the provider refuses takes the stamp back, so the cancellation can be
 * asked for again; a process that dies in between leaves it, and the refund still owed then goes through the refund
 * verb by hand.
 */
final readonly class PrepaidTermCancellation
{
    public function __construct(
        private BillingAdmin $admin,
    ) {}

    /**
     * Refund the unused part of a prepaid term, which corrects both links of the chain once the provider confirms.
     *
     * @param  Model  $owner  the buyer the term was sold to, whom the refund and its audit record name
     * @param  MerchantCharge  $charge  the routed charge the term was paid on
     * @param  Money  $term  what the buyer paid for the whole term, frozen at the sale
     * @param  int  $periodsUsed  periods consumed before the cancellation, 0 cancels before it starts
     * @param  int  $periodsInTerm  how many periods the term was sold as
     * @param  ?string  $reason  what happened in this case, recorded beside the refund
     * @param  ?Model  $actor  who did it, when somebody did
     * @return ?RefundResult what the provider answered, or null when the term was used up and nothing is owed
     *
     * @throws CancellationUnavailable when this charge's term was canceled already
     */
    public function cancel(
        Model $owner,
        MerchantCharge $charge,
        Money $term,
        int $periodsUsed,
        int $periodsInTerm,
        ?string $reason = null,
        ?Model $actor = null,
    ): ?RefundResult {
        // The amount is asked for, never computed here. A second rounding rule is the divergence nobody notices,
        // because both numbers look reasonable, and on an uneven term the two answers differ by a cent each time.
        $refund = ProratedTermRefund::unusedPortion($term, $periodsUsed, $periodsInTerm);

        // A term canceled at its very end owes nothing. Nothing is stamped, nothing moves, and no document is
        // asked for: a refund of zero would still be a line in the books.
        if (! $refund->isPositive()) {
            return null;
        }

        $this->stamp($charge);

        $result = $this->admin->refund(
            $owner,
            $charge->charge_reference,
            $refund,
            $reason,
            'prepaid-term-cancellation:'.$charge->id,
            $actor,
            RefundKind::UnusedPrepaidPeriod,
        );

        if (! $result->successful) {
            $this->unstamp($charge);
        }

        return $result;
    }

    /** Stamp the term as canceled, or refuse when it is: two calls at the same moment wait for each other here. */
    private function stamp(MerchantCharge $charge): void
    {
        $charge->getConnection()->transaction(static function () use ($charge): void {
            $row = MerchantCharge::model()::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($row->term_canceled_at instanceof Carbon) {
                throw CancellationUnavailable::termAlreadyCanceled($row->provider, $row->charge_reference, $row->term_canceled_at);
            }

            $row->forceFill(['term_canceled_at' => Carbon::now()])->save();
        });
    }

    /** Take the stamp back after a refused refund, which moved nothing and corrected nothing. */
    private function unstamp(MerchantCharge $charge): void
    {
        MerchantCharge::model()::query()->whereKey($charge->id)->update(['term_canceled_at' => null]);
    }
}
