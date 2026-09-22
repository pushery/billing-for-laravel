<?php

declare(strict_types=1);

namespace Pushery\Billing\Proration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Contracts\TierResolver;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\Support\PeriodResolver;
use Pushery\Billing\ValueObjects\BillingPeriod;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;

/**
 * Proration for a provider that has none: the unused remainder of the current plan becomes customer
 * credit, and the next order is offset against it.
 *
 * Stripe prorates on its own side, which is what DelegatedProrationStrategy defers to. A local-engine driver
 * has no such thing — a swap there is simply a new order at the new price, and without this the customer
 * pays twice for the same days. Bind this in place of the delegated strategy on such a driver.
 *
 * WHY THE CREDIT IS BOOKED AND THE CHARGE IS NOT. A swap has two halves: the unused old time (a credit,
 * which is money the customer is owed and which nothing else in the system will remember) and the new
 * plan's remaining time (a charge, which the next order raises anyway). Booking the credit here and
 * leaving the charge to the order is what keeps the two from being counted twice — this strategy never
 * takes money, it only ever records what is owed back.
 */
final readonly class CreditBalanceProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private ProrationCalculator $calculator,
        private CreditLedger $ledger,
        private PlanCatalog $plans,
        private TierResolver $tiers,
        private PeriodResolver $periods,
        private BillingEventLog $log,
    ) {}

    /**
     * The net amount due now for the swap: the new plan's prorated charge less the unused credit.
     *
     * Positive is an upgrade (the customer owes the difference), negative is a downgrade (they are owed
     * it). Null when the current plan cannot be priced — a preview that cannot be computed is shown as
     * unavailable rather than as a number that might be wrong, the same way the delegated strategy
     * refuses to fabricate one.
     */
    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        $current = $this->currentPlan($billable);

        if (! $current instanceof Plan) {
            return null;
        }

        [$remaining, $length] = $this->clock($this->periods->forOwner($billable));

        return $this->calculator->netForSwap($current->amount, $newPlan->amount, $remaining, $length);
    }

    public function applySwap(Model $billable, Plan $newPlan): void
    {
        $current = $this->currentPlan($billable);

        if (! $current instanceof Plan) {
            return;
        }

        $period = $this->periods->forOwner($billable);
        [$remaining, $length] = $this->clock($period);

        // The invoice whose consideration this credit gives back -- and, where there is one, the
        // amount it gives back a share OF. Without it the credit is prorated from the plan's LIST
        // price, and a customer who paid a discounted invoice would be credited more than they ever
        // paid: the excess is then a gift rather than a returned consideration, which is a different
        // thing in the books and in the tax on them.
        $invoice = $this->invoiceForPeriod($billable, $period, $current->amount->currency);

        $basis = $invoice instanceof InvoiceRecord
            ? new Money((int) $invoice->total_minor, $current->amount->currency)
            : $current->amount;

        $unused = $this->calculator->proratedAmount($basis, $remaining, $length);

        // A swap at the very end of a period leaves nothing unused. Writing a zero movement would add a
        // ledger entry that says nothing happened, which is noise in the one place that has to stay
        // readable when a customer disputes their balance.
        if ($unused->isZero()) {
            return;
        }

        // The SOURCE is what makes this credit bookable later. A proration credit gives back part of
        // an already invoiced and already taxed consideration, so the correction it eventually needs
        // belongs to THAT invoice, at THAT invoice's tax rate. Written here or never: nothing
        // downstream can reconstruct which invoice a balance movement came from.
        $this->ledger->credit(
            $billable,
            $unused,
            CreditReason::ProrationCredit,
            $invoice instanceof InvoiceRecord ? CreditSource::for($invoice) : null,
        );

        // The balance alone says WHAT the customer has, never WHY. Without this line a support agent
        // looking at a credit has no way to tell a proration from a refund or a goodwill gesture.
        $this->log->record('billing.proration_credited', $billable, [
            'from_tier' => $current->key,
            'to_tier' => $newPlan->key,
            'amount' => $unused->minorUnits,
            'currency' => $unused->currency,
            // Null is a fact worth recording, not an omission: it says the reading could not name
            // one invoice for this period, so this credit stays out of the books until it can.
            'invoice_id' => $invoice?->getKey(),
        ], AuditSource::System);
    }

    /**
     * The paid invoice this period's consideration was paid on, or null when no ONE invoice answers.
     *
     * ## Why a match on fields rather than "the latest paid invoice"
     *
     * There is no stored edge from an invoice to the period it covers — `InvoiceRecord` carries an
     * owner, a status and an `issued_at`, and the period lives on the subscription. So the question
     * is answered by intersecting what IS stored: same owner, same currency, paid, issued inside
     * this period. Taking the most recent paid invoice instead would be an ordering guess, and the
     * thing it guesses at is which invoice a later tax correction belongs to.
     *
     * ## Ambiguity is not resolved, it is declined
     *
     * Two matching invoices answer null, exactly as none does. "First match wins" would be the same
     * guess with the guessing hidden — and a credit attached to the wrong invoice corrects the wrong
     * taxable base, which is worse than a credit attached to none.
     *
     * ## What null costs, and why it is the honest fallback
     *
     * The credit is written without a source and prorated from the plan's list price, which is what
     * this class did for every swap before. The amount then appears in the monthly reconciliation as
     * a named difference rather than as a booking nobody can justify. An application whose invoices
     * come from a provider and are dated outside the period keeps getting that honest number instead
     * of a wrong attribution.
     */
    private function invoiceForPeriod(Model $billable, BillingPeriod $period, string $currency): ?InvoiceRecord
    {
        $matches = InvoiceRecord::model()::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('status', InvoiceStatus::Paid)
            ->where('currency', $currency)
            ->whereBetween('issued_at', [$period->start, $period->end])
            // Three is as good as two for this question, and it keeps a pathological schema from
            // loading a customer's whole invoice history to answer "is there exactly one".
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function currentPlan(Model $billable): ?Plan
    {
        return $this->plans->planFor($this->tiers->resolve($billable)->key);
    }

    /**
     * Where the clock sits in the current period: seconds left, and how long the period is.
     *
     * Both are taken from the SAME period so they cannot disagree, and the remainder is measured from
     * now rather than stored, because a swap is priced at the moment it happens.
     *
     * @return array{int, int}
     */
    private function clock(BillingPeriod $period): array
    {
        $now = Carbon::now()->utc();

        // Negative would mean the period already ended; the calculator clamps it, but returning a
        // negative here would let a caller reading this pair reach a different conclusion than the
        // calculator does about the same swap.
        $remaining = max(0, $now->diffInSeconds($period->end, false));
        $length = max(0, $period->start->diffInSeconds($period->end, false));

        return [(int) $remaining, (int) $length];
    }
}
