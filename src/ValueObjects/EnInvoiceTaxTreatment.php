<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Invoicing\Line;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * How one document is taxed, derived once and carried whole through both EN 16931 writers.
 *
 * ## What this replaces
 *
 * Three primitives — `bool $reverseCharge`, `bool $exempt`, `?string $exemptionReason` — traveled through
 * eleven signatures in `src/Invoicing`, and ten of those took the `InvoiceRecord` they had just been derived
 * from as well. The object and its own derivation rode through the same parameter list.
 *
 * The sharpest of them was `XRechnungInvoice::taxCategory()`: three consecutive `bool` parameters, two with
 * defaults. Swapping two of those compiles, runs, and writes an EN 16931 category element with the wrong
 * category or without its reason text — the class of error a conformant validator rejects at the RECIPIENT,
 * not here.
 *
 * ## Why it carries the bands and the totals too
 *
 * Because that is the half that was actually duplicated. The derivation of `net`, `tax` and the band
 * breakdown stood byte-identically in both renderers' own `render()` — eight places in all. A value object
 * holding only the three tax characteristics would have left every one of them standing and added a class
 * beside them.
 *
 * The two renderers read this rule the same way today by coincidence, not by construction. Correct it in one
 * and the other keeps the old reading, and the resulting defect is FORMAT-SPECIFIC: it shows up in whichever
 * of XRechnung or ZUGFeRD nobody happens to be looking at. That divergence is not hypothetical in this
 * package — the two Stripe purchase lanes drifted apart on exactly this shape.
 *
 * ## What it is not
 *
 * Not a renderer, and not a tax decision. Every value here is read off a document that was already issued and
 * frozen; nothing is computed about what the tax OUGHT to be. `EnInvoiceTaxCategory::for()` remains the place
 * that decides a category, and its `bool $exempt` is a genuine decision input rather than state passed
 * through — which is why it keeps its own signature.
 */
final readonly class EnInvoiceTaxTreatment
{
    /**
     * @param  list<Line>  $lines  the document's lines, empty for a lineless invoice
     * @param  list<array{rate: float, taxable: int, tax: int}>  $bands  the per-rate breakdown BT-110 sums over
     * @param  int  $net  the document net in minor units
     * @param  int  $tax  the document tax in minor units — ALWAYS zero under a reverse charge
     * @param  ?string  $exemptionReason  BT-120, the reason text, or null where none is stated
     */
    public function __construct(
        public InvoiceRecord $invoice,
        public array $lines,
        public array $bands,
        public int $net,
        public int $tax,
        public bool $reverseCharge,
        public bool $exempt,
        public ?string $exemptionReason,
    ) {}

    /** Whether this document carries no lines, and its totals therefore come from the stored figures. */
    public function isLineless(): bool
    {
        return $this->lines === [];
    }
}
