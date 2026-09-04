<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Invoice;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Pushery\Billing\ValueObjects\InvoicePage;
use Pushery\Billing\ValueObjects\Money;
use Throwable;

/**
 * Invoices read from the package's own table, for a driver whose engine is local.
 *
 * Stripe's adapter asks Stripe. A local driver has nobody to ask — it issued the document itself — so
 * this reads the rows back. That is not merely equivalent: it means the invoices screen renders with no
 * network call at all, which is the difference between a page that works while the provider is down and
 * one that does not.
 *
 * Deliberately NOT named for a provider and deliberately not in a driver's namespace. Every local-engine
 * driver needs exactly this, and a copy per driver is how two implementations of one thing start.
 *
 * ## Ownership is a filter, not a check afterwards
 *
 * `download` scopes the query by the billable rather than fetching by id and comparing. The two read the
 * same until somebody edits one of them: a fetch-then-compare grows a branch where the comparison is
 * skipped, and that branch hands one customer another's invoice. Filtering makes the wrong row
 * unreachable rather than rejected.
 */
final readonly class LocalInvoices implements Invoices
{
    public function __construct(private InvoiceDocumentRenderer $renderer) {}

    public function recent(Model $billable, int $perPage = 24): InvoicePage
    {
        // One more than asked for: the extra row answers `hasMore` without a second COUNT query over a
        // table that only grows.
        $records = $this->ownedBy($billable)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $records->count() > $perPage;

        $rows = $records->take($perPage)
            // A row carrying neither an issue date nor a creation timestamp cannot be placed on a
            // timeline, and inventing one would put it at today's date among documents that are years
            // old. Skipped rather than dated by guess; a persisted row always has at least the latter.
            ->filter(static fn (InvoiceRecord $record): bool => ($record->issued_at ?? $record->created_at) !== null)
            ->map(static fn (InvoiceRecord $record): Invoice => new Invoice(
                (string) $record->id,
                $record->issued_at ?? $record->created_at ?? throw new LogicException('unreachable: filtered above'),
                new Money($record->total_minor, $record->currency),
                $record->status,
                $record->number,
            ))
            ->all();

        return new InvoicePage(array_values($rows), $hasMore);
    }

    public function download(Model $billable, string $invoiceId): ?InvoiceDownload
    {
        $record = $this->ownedBy($billable)->whereKey($invoiceId)->first();

        if (! $record instanceof InvoiceRecord) {
            return null;
        }

        try {
            $pdf = $this->renderer->pdf($record);
        } catch (Throwable) {
            // No PDF renderer is installed, which is the shipped default — the package produces the
            // document and never the paper. Null reads as "not downloadable" on the screen rather than
            // turning a missing optional dependency into an error page.
            return null;
        }

        return new InvoiceDownload(
            sprintf('%s.pdf', $record->number ?? (string) $record->id),
            $pdf,
        );
    }

    /**
     * Every read is scoped to the owner HERE, so no caller can forget it.
     *
     * The native return type carries the class and the docblock carries the generic, which is the only way
     * to state both: a bare `Builder` says nothing about what it builds, and a docblock alone leaves the
     * signature untyped — which the type-coverage floor treats as a hole, correctly. Reading one of the two
     * as sufficient is how a method ends up documented and unenforced at the same time.
     *
     * @return Builder<InvoiceRecord>
     */
    private function ownedBy(Model $billable): Builder
    {
        return InvoiceRecord::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey());
    }
}
