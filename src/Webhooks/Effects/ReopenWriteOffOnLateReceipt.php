<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Events\WriteOffRecovered;
use Pushery\Billing\Marketplace\RecoveredReceivable;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\BillingEventLog;
use RuntimeException;

/**
 * A payment arriving against a receivable somebody already gave up on.
 *
 * ## The distinction this exists to make
 *
 * A correction issued because the consideration would not be received is a judgement about the future, and
 * the future is allowed to disagree: when the money turns up, the write-off was wrong and the tax goes back.
 * A correction issued because the consideration was HANDED BACK can never be reopened — a payment afterwards
 * is a new transaction with its own document.
 *
 * The two produce identical figures in identical periods. Nothing in the amounts tells them apart, which is
 * why the reason lives on the document and why `RecoveredReceivable` is asked rather than the totals.
 *
 * ## Why the match has to be exact, and why ambiguity does nothing
 *
 * `PaymentSucceeded` names a customer, an amount and a payment reference. It does NOT name the receivable it
 * settles — the provider has no idea one was ever written off. So the link has to be inferred, and inference
 * is where an automatic tax correction would become a guess with a document behind it.
 *
 * This effect therefore acts on exactly one shape: a single reopenable correction for this owner, in this
 * currency, for this amount. Two candidates, or none, and it does nothing at all. That is not a gap — the
 * ambiguous case belongs on `RecoveredReceivable::provisionalWriteOffs()`, the review list that exists
 * precisely because a write-off nobody can list is one nobody revisits. Better an open item a human closes
 * than a correction nobody asked for.
 *
 * ## Fail-closed on silence
 *
 * A correction that states no reason is not reopened. `reversible()` answers false for it, so it never
 * reaches the candidate set — putting a sale back on the strength of a missing field is the one outcome
 * worse than leaving it open.
 *
 * ## Single-seller installs are untouched
 *
 * Registered unconditionally, like the other payment effects, and inert without the rows it reads: an
 * install that never writes anything off as uncollectible has no correction carrying that reason, so the
 * candidate set is empty on every payment and nothing is dispatched.
 */
final readonly class ReopenWriteOffOnLateReceipt implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private RecoveredReceivable $recovered,
        private Dispatcher $events,
        private BillingEventLog $log,
    ) {}

    public function __invoke(PaymentSucceeded $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        $candidates = $this->reopenableFor($owner, $event);

        // Nothing to reopen is the ordinary case — most payments settle something that was never written
        // off. More than one is the case that must NOT be resolved by picking: two write-offs of the same
        // amount in the same currency are indistinguishable from here, and choosing between them would
        // reopen the wrong period as often as the right one.
        if (count($candidates) !== 1) {
            return;
        }

        $correction = $candidates[0];

        $this->events->dispatch(new WriteOffRecovered($correction, $event->amount, $event->reference));

        $this->log->record('invoice.write_off_recovered', $owner, [
            'correction_id' => $correction->id,
            'correction_number' => $correction->number,
            'reference' => $event->reference,
            'amount' => $event->amount->minorUnits,
            'currency' => $event->amount->currency,
        ], AuditSource::Webhook);
    }

    /**
     * The corrections this payment could be putting back — asked one at a time, never filtered by reason
     * in the query.
     *
     * The query narrows to what CANNOT match (another owner, another currency, another amount, a document
     * that corrects nothing); the judgement about whether a reason reopens is left to the one class that
     * owns it. A `where` on the reason column here would be the same rule spelled twice, and the second
     * spelling is the one that stops being updated.
     *
     * @return list<InvoiceRecord>
     */
    private function reopenableFor(Model $owner, PaymentSucceeded $event): array
    {
        /** @var list<InvoiceRecord> $corrections */
        $corrections = InvoiceRecord::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->whereNotNull('credited_invoice_id')
            ->where('currency', $event->amount->currency)
            // A correction is stored with the sign of the movement it records, and the payment arrives
            // positive. Matching on the magnitude keeps this from depending on which sign a given issuer
            // chose, which is a convention rather than a fact about the money.
            ->whereIn('total_minor', [$event->amount->minorUnits, -$event->amount->minorUnits])
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_filter(
            $corrections,
            $this->recovered->reversible(...),
        ));
    }

    /**
     * Once per payment.
     *
     * The same reference the receipt dedups on, and for the same reason: a provider redelivering one
     * "payment succeeded" mints a fresh event id, and deduping on that would reopen a write-off twice for
     * money that arrived once.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof PaymentSucceeded) {
            throw new RuntimeException('ReopenWriteOffOnLateReceipt only handles PaymentSucceeded events.');
        }

        return $event->reference;
    }
}
