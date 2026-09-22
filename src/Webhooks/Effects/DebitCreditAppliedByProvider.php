<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Events\InvoiceFinalized;
use Pushery\Billing\Models\CreditLedgerEntry;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\CreditLedger;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\Money;

/**
 * Brings credit the PROVIDER spent back onto this package's ledger.
 *
 * ## The hole this closes
 *
 * A driver with a customer balance mirrors credit onto the provider so it reduces the next invoice
 * automatically — `StripeCreditSync::push()` writes a real customer balance transaction for it. `CreditSync`
 * calls this package's ledger "the source of truth", and until now the mirror only ever pointed one way: the
 * provider consumed the balance on its own, and nothing told the ledger.
 *
 * Measured on the tree: the only production caller of `CreditLedger::debit()` was a refund, and the only
 * caller of `spendUpTo()` is the LOCAL billing engine — which never runs on a lane where the provider raises
 * the invoice. So from the first provider invoice that consumed credit onward, the local balance was too
 * high, permanently, and it is the balance the account hub shows the customer and the one a local charge
 * spends from. A customer could be handed the same hundred euros twice.
 *
 * ## Why the source is the invoice RECORD
 *
 * The ledger's `source` is a morph, so its id is an integer and a provider's own `in_…` identifier does not
 * fit. Keying on the record is better anyway: it is the row a support agent already has in front of them
 * when they ask why a balance moved.
 *
 * This effect therefore runs AFTER {@see PersistInvoice}, and says nothing when the record is missing rather
 * than debiting against a source nobody can open. A missing record means the invoice was not persisted, and
 * an unexplained movement is the state the ledger's own docblock says it must never reach.
 *
 * ## Idempotency is money here
 *
 * A provider redelivers, and `invoice.finalized` and `invoice.payment_succeeded` both carry the same invoice
 * object. A second debit is not a duplicate row, it is a customer charged twice for one offset — so the
 * ledger is asked whether it already carries this reason against this record, and the write is skipped if it
 * does. Read rather than caught: a unique index would make the second delivery an exception to swallow, and
 * swallowing exceptions around money is how a real failure gets mistaken for a replay.
 */
final readonly class DebitCreditAppliedByProvider
{
    public function __construct(
        private CustomerDirectory $directory,
        private CreditLedger $ledger,
    ) {}

    public function __invoke(InvoiceFinalized $event): void
    {
        $snapshot = $event->invoice;

        // Zero covers both "this driver holds no balance" and "this invoice consumed none". They call for
        // the same nothing, and distinguishing them here would be a branch with one outcome.
        if ($snapshot->creditAppliedMinor <= 0) {
            return;
        }

        $owner = $this->directory->ownerForReference($snapshot->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own — the same silence PersistInvoice keeps
        }

        $record = InvoiceRecord::model()::query()
            ->where('provider', $snapshot->provider)
            ->where('provider_id', $snapshot->providerId)
            ->first();

        if (! $record instanceof InvoiceRecord) {
            return;
        }

        if ($this->alreadyDebited($record)) {
            return;
        }

        $this->ledger->debit(
            $owner,
            Money::of($snapshot->creditAppliedMinor, $snapshot->currency),
            CreditReason::ProviderInvoiceOffset,
            CreditSource::for($record),
        );
    }

    /** Whether this invoice already took its offset off the ledger. */
    private function alreadyDebited(InvoiceRecord $record): bool
    {
        return CreditLedgerEntry::model()::query()
            ->where('source_type', $record->getMorphClass())
            ->where('source_id', $record->getKey())
            ->where('reason', CreditReason::ProviderInvoiceOffset)
            ->exists();
    }
}
