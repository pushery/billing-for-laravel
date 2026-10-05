<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * The PDF an invoice was issued as, where the application kept one.
 *
 * `billing_invoices.pdf_path` records where the application keeps the issued PDF, on the disk named by
 * `billing.invoices.pdf_disk`. A download serves that file before it renders anything. That is not an
 * optimization: everything under a renderer moves over the years an invoice must stay readable — a corrected
 * rate table, an updated address, an improved writer — so a re-render years later resembles the document the
 * recipient holds without being it, and the disagreement surfaces in a dispute, where the other party is the
 * one holding the original. It is the same reasoning `DocumentArtifactStore` applies to the XML forms; this is
 * the human-readable half, which only the application can keep.
 *
 * Every download path reads the file here, so the rule holds whichever invoice reader the driver binds.
 */
final readonly class KeptInvoicePdf
{
    /**
     * The issued PDF as it was kept, or null when there is none to serve.
     *
     * Null covers three DIFFERENT situations on purpose, and only one of them is quiet:
     *
     *  - **No path recorded.** Nobody kept a PDF. Nothing to say — this is the shipped default, and no disk
     *    is touched.
     *  - **A path recorded but no disk configured.** The application wrote where it keeps files and never told
     *    the package which disk that is. Logged as a warning: the value is being ignored, and an operator who
     *    set one and not the other should learn it from something other than a support case.
     *  - **A path recorded, a disk configured, and the file GONE.** Logged as an ERROR, because the row
     *    promises an archived document and the archive did not keep it. That is an incident.
     *
     * The caller renders where it can in every one of them, deliberately. Refusing would lock an owner out of
     * their own invoice to make a point about an archive they do not control, and the document the package
     * can still produce is worth more to them than a dead link. What must not happen is the substitution
     * going UNRECORDED — so the divergence is loud in the log and invisible in the response, which is the
     * right way round: the reader gets their invoice, the operator gets the incident.
     */
    public function contents(InvoiceRecord $invoice): ?string
    {
        $path = $this->path($invoice);

        if ($path === null) {
            return null;
        }

        $disk = $this->disk();

        if ($disk === null) {
            Log::warning('An invoice records a kept PDF but billing.invoices.pdf_disk is not configured, so the stored file cannot be served. Where a PDF renderer is bound, a fresh render is served instead.', [
                'invoice' => $invoice->number ?? $invoice->id,
                'pdf_path' => $path,
            ]);

            return null;
        }

        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            Log::error('An invoice records a kept PDF that is not on the configured disk. Where a PDF renderer is bound, the document served is a fresh render and may differ from the one its recipient holds.', [
                'invoice' => $invoice->number ?? $invoice->id,
                'pdf_path' => $path,
                'disk' => $disk,
            ]);

            return null;
        }

        return $filesystem->get($path);
    }

    /**
     * Whether the invoice names a kept file on a configured disk, answered without touching the disk, so a list
     * of invoices can offer the download without asking storage once per row. A file that turns out to be
     * gone is reported when it is asked for.
     */
    public function isRecorded(InvoiceRecord $invoice): bool
    {
        return $this->path($invoice) !== null && $this->disk() !== null;
    }

    /** The recorded path, where there is one. Whitespace is not a location. */
    private function path(InvoiceRecord $invoice): ?string
    {
        $path = $invoice->pdf_path;

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    private function disk(): ?string
    {
        $disk = Config::get('billing.invoices.pdf_disk');

        return is_string($disk) && trim($disk) !== '' ? $disk : null;
    }
}
