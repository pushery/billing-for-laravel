<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use LogicException;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\ValueObjects\Invoice;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Pushery\Billing\ValueObjects\InvoicePage;
use Pushery\Billing\ValueObjects\Money;

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
    public function __construct(
        private InvoiceDocumentRenderer $renderer,
        private KeptInvoicePdf $kept = new KeptInvoicePdf,
    ) {}

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

        // A row the screen offers to download: the route that streams it is registered, and `download()` can
        // hand a document over, either because a PDF toolchain is bound or because the application kept the
        // issued file. The screen shows the link only for a row that names where to get it.
        $routed = Route::has('billing.account.invoice-download');
        $renders = $this->renderer->rendersPdf();

        $rows = $records->take($perPage)
            // A row carrying neither an issue date nor a creation timestamp cannot be placed on a
            // timeline, and inventing one would put it at today's date among documents that are years
            // old. Skipped rather than dated by guess; a persisted row always has at least the latter.
            ->filter(static fn (InvoiceRecord $record): bool => ($record->issued_at ?? $record->created_at) !== null)
            ->map(fn (InvoiceRecord $record): Invoice => new Invoice(
                (string) $record->id,
                $record->issued_at ?? $record->created_at ?? throw new LogicException('unreachable: filtered above'),
                new Money($record->total_minor, $record->currency),
                $record->status,
                $record->number,
                $routed ? $this->downloadUrl($record, $renders) : null,
            ))
            ->all();

        return new InvoicePage(array_values($rows), $hasMore);
    }

    /** Where the screen offers the row's document, or null where `download()` would have none to hand over. */
    private function downloadUrl(InvoiceRecord $record, bool $renders): ?string
    {
        if (! $renders && ! $this->kept->isRecorded($record)) {
            return null;
        }

        return URL::route('billing.account.invoice-download', ['invoiceId' => (string) $record->id]);
    }

    public function download(Model $billable, string $invoiceId): ?InvoiceDownload
    {
        $record = $this->record($billable, $invoiceId);

        if (! $record instanceof InvoiceRecord) {
            return null;
        }

        $filename = sprintf('%s.pdf', $record->number ?? (string) $record->id);

        // The file the invoice was issued as, where the application kept one, before any render of it.
        $kept = $this->kept->contents($record);

        if ($kept !== null) {
            return new InvoiceDownload($filename, $kept);
        }

        // No PDF renderer is installed, which is the shipped default — the package produces the document and
        // never the paper. Null answers the download route with a 404 rather than turning a missing optional
        // dependency into an error page. A renderer that is bound and fails, and a document the margin guard
        // refuses, are errors rather than an absent document, and they reach the exception handler.
        if (! $this->renderer->rendersPdf()) {
            return null;
        }

        return new InvoiceDownload($filename, $this->renderer->pdf($record));
    }

    /**
     * The owner's stored invoice behind an id from a route, or null where the owner has none by that id.
     *
     * Another owner's invoice and an id that names no invoice answer alike, so the id sequence every customer
     * shares cannot be probed for which ids exist. The table's key is a bigint sequence, and an id that is not
     * a positive integer within its range names no invoice; it is answered without a query, because such a
     * value against an integer key is an error on PostgreSQL rather than an empty result.
     */
    public function record(Model $billable, string $invoiceId): ?InvoiceRecord
    {
        return $this->canBeAKey($invoiceId) ? $this->ownedBy($billable)->whereKey((int) $invoiceId)->first() : null;
    }

    /**
     * Whether an id from a route can be a key of the invoice table: digits with no leading zero, and no larger
     * than a bigint holds. The bound is compared as text, because a longer number cast to an integer first
     * would land inside the range it is being tested against.
     */
    private function canBeAKey(string $id): bool
    {
        if (! ctype_digit($id) || $id[0] === '0') {
            return false;
        }

        $largest = (string) PHP_INT_MAX;

        return strlen($id) < strlen($largest) || (strlen($id) === strlen($largest) && strcmp($id, $largest) <= 0);
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
        return InvoiceRecord::model()::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey());
    }
}
