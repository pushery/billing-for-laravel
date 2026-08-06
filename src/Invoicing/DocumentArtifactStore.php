<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Pushery\Billing\Models\DocumentArtifact;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Keeps the electronic document exactly as it left, and answers whether it still matches.
 *
 * ## Why a copy of bytes and not a re-render
 *
 * Re-rendering from the database years later produces something that resembles what was issued and is not
 * it. Everything under a serializer moves over that span — a corrected rate table, an updated address, an
 * improved writer — and each change silently yields a "copy" the recipient's version disagrees with. When
 * the disagreement matters, it matters in a dispute, and the party holding the original will be the other
 * one.
 *
 * ## Storing it twice is refused, not silently overwritten
 *
 * A document has one issued form. A second store of the same number and syntax means either a bug or a
 * re-issue that should have carried its own number — either way, quietly replacing the first would destroy
 * the evidence of whichever it was.
 */
final readonly class DocumentArtifactStore
{
    public function __construct(
        private XRechnungInvoice $ubl,
        private ZugferdCiiInvoice $cii,
    ) {}

    /** Store the UBL and CII forms of a document as they render right now. */
    public function storeBoth(InvoiceRecord $invoice, CarbonInterface $issuedAt): void
    {
        $this->store($invoice, 'ubl', $this->ubl->render($invoice), $issuedAt);
        $this->store($invoice, 'cii', $this->cii->render($invoice), $issuedAt);
    }

    /** Store one syntax. Returns the stored artifact; refuses a second store of the same document form. */
    public function store(InvoiceRecord $invoice, string $syntax, string $contents, CarbonInterface $issuedAt): DocumentArtifact
    {
        return DocumentArtifact::query()->create([
            'owner_type' => $invoice->owner_type,
            'owner_id' => $invoice->owner_id,
            'document_number' => $invoice->number ?? '',
            'syntax' => $syntax,
            'issued_at' => $issuedAt,
            'checksum' => hash('sha256', $contents),
            'contents' => $contents,
        ]);
    }

    /** What was issued, byte for byte, or null where nothing was kept. */
    public function issued(string $documentNumber, string $syntax): ?string
    {
        $artifact = DocumentArtifact::query()
            ->where('document_number', $documentNumber)
            ->where('syntax', $syntax)
            ->first();

        return $artifact instanceof DocumentArtifact ? $artifact->contents : null;
    }

    /**
     * Whether re-rendering the document today would produce what was issued.
     *
     * A mismatch is information, not a failure: it says the data underneath has moved since. What must never
     * happen is that a caller receives the re-render BELIEVING it to be the original — which is exactly what
     * a store that only kept the row and re-rendered on demand would do, every time, silently.
     */
    public function matchesIssued(InvoiceRecord $invoice, string $syntax): bool
    {
        // A document with no number was never issued, so there is nothing it could match.
        $stored = $invoice->number === null ? null : $this->issued($invoice->number, $syntax);

        if ($stored === null) {
            return false;
        }

        return hash('sha256', $stored) === hash('sha256', $this->rerender($invoice, $syntax));
    }

    private function rerender(InvoiceRecord $invoice, string $syntax): string
    {
        return $syntax === 'ubl' ? $this->ubl->render($invoice) : $this->cii->render($invoice);
    }
}
