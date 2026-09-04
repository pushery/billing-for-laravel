<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Invoicing\InvoiceDocumentRenderer;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\LocalBillingEngine;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\Support\SubscriptionReconciler;
use Pushery\Billing\ValueObjects\InvoiceDownload;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The hosted-portal bridge and the checkout return: redirects the signed-in owner to the provider's own
 * billing portal, and lands them back from a hosted checkout with their subscription already reconciled.
 * When no portal is available (the driver has none, or the owner has no provider customer yet) the portal
 * answers 404, so the app can fall back to the in-app account-hub screens.
 */
final class BillingController
{
    public function portal(): RedirectResponse
    {
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        $owner = Container::getInstance()->make(BillingEntityResolver::class)->ownerFor($actor);
        // The portal URL comes from the driver; validate it is an absolute http(s) URL before sending the
        // owner away, so a bad payload can never turn the portal link into a script or open-redirect target.
        $url = SafeExternalUrl::orNull(Container::getInstance()->make(HostedPortal::class)->url($owner));

        if ($url === null) {
            throw new NotFoundHttpException;
        }

        return Redirect::away($url);
    }

    /**
     * The hosted-checkout return URL. The customer is back from the provider — possibly before the webhook
     * arrived — so the subscription is reconciled onto the local row NOW, then they are sent to the
     * subscription screen. A failed reconcile is reported and swallowed: the webhook is the durable path,
     * and a customer must never be shown an error page after a successful payment.
     */
    public function checkoutReturn(): RedirectResponse
    {
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        $owner = Container::getInstance()->make(BillingEntityResolver::class)->ownerFor($actor);

        // ASKED FIRST, because there is nothing to reconcile against under a driver whose cycle this package
        // runs itself. `SubscriptionSync` is bound only by the Stripe provider, and both driver providers
        // register unconditionally — so on a Mollie install this used to ask STRIPE about a customer whose
        // reference Mollie wrote. An outbound call to the wrong provider on the page a customer lands on
        // straight after paying, and the catch below then reported the resulting error: one entry in the
        // install's error tracker per completed sale, looking exactly like a real failure. An error stream
        // that fires on every sale gets muted, and after that the real one is gone too.
        //
        // Decided on the ENGINE rather than a driver name: a local engine holds the cycle here, so there is
        // no provider-side subscription to read back. The webhook is the durable path anyway — this
        // reconcile is a courtesy for a customer who beats it home, and where there is nothing to pull
        // there is no courtesy to do.
        if (! Container::getInstance()->make(BillingManager::class)->driver()->engine() instanceof LocalBillingEngine) {
            try {
                Container::getInstance()->make(SubscriptionReconciler::class)->syncFromProvider($owner);
            } catch (Throwable $e) {
                Container::getInstance()->make(ExceptionHandler::class)->report($e);
            }
        }

        // Flag the subscription screen as "activating": if the webhook has not landed yet the reconcile above
        // may not have recorded the subscription, so the screen shows a pending state and polls until it does.
        return Redirect::route('billing.account.subscription', ['activating' => 1]);
    }

    /**
     * Stream an owner's invoice document from a dedicated, bookmarkable route (rather than a Livewire action),
     * so a row's download link is a plain href that works without JavaScript. The driver owner-checks the id
     * and returns null for anything not the signed-in owner's — a 404 here, so one owner can never pull
     * another's document by guessing an id.
     */
    public function downloadInvoice(string $invoiceId): StreamedResponse
    {
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        $owner = Container::getInstance()->make(BillingEntityResolver::class)->ownerFor($actor);
        $document = Container::getInstance()->make(Invoices::class)->download($owner, $invoiceId);

        // A provider that hosts its own PDFs (Stripe) answers here. A local-engine driver has none,
        // so the package renders the stored invoice itself — a foreign invoice is refused (403), an absent one
        // is a 404, so one owner can never pull another's document by guessing an id.
        if ($document === null) {
            $document = $this->renderLocalInvoice($owner, $invoiceId);
        }

        if ($document === null) {
            throw new NotFoundHttpException;
        }

        return Response::streamDownload(
            function () use ($document): void {
                echo $document->contents;
            },
            $document->filename,
            // noindex: a private financial document must never be indexed if the URL leaks into a crawler.
            ['Content-Type' => $document->mimeType, 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }

    /**
     * Render one of the package's OWN stored invoices as a document — the local path for a driver without
     * hosted PDFs. The invoice is looked up by id and ownership-checked here: a row belonging to another
     * owner is refused (403), so a shared id space cannot leak one owner's document to another.
     *
     * The KEPT file wins over a fresh render where there is one. That is not an optimization: everything
     * under a renderer moves over the years an invoice must stay readable — a corrected rate table, an
     * updated address, an improved writer — so a re-render years later resembles the document the recipient
     * holds without being it, and the disagreement surfaces in a dispute, where the other party is the one
     * holding the original. It is the same reasoning `DocumentArtifactStore` applies to the XML forms; this
     * is the human-readable half, which only the consumer can keep.
     */
    private function renderLocalInvoice(Model $owner, string $invoiceId): ?InvoiceDownload
    {
        $invoice = InvoiceRecord::query()->find($invoiceId);

        if ($invoice === null) {
            return null; // absent → 404
        }

        $ownerKey = $owner->getKey();
        $sameOwner = $invoice->owner_type === $owner->getMorphClass()
            && is_scalar($ownerKey)
            && (string) $invoice->owner_id === (string) $ownerKey;

        if (! ($sameOwner)) {
            throw new HttpException(403);
        }

        $number = $invoice->number ?? (string) $invoice->id;
        $kept = $this->keptPdf($invoice);

        if ($kept !== null) {
            return new InvoiceDownload("invoice-{$number}.pdf", $kept);
        }

        $pdf = Container::getInstance()->make(InvoiceDocumentRenderer::class)->pdf($invoice);

        return new InvoiceDownload("invoice-{$number}.pdf", $pdf);
    }

    /**
     * The issued PDF as it was kept, or null when there is none to serve.
     *
     * Null covers three DIFFERENT situations on purpose, and only one of them is quiet:
     *
     *  - **No path recorded.** Nobody kept a PDF. Nothing to say — this is the shipped default and the
     *    route renders exactly as it always has.
     *  - **A path recorded but no disk configured.** The consumer wrote where they keep files and never
     *    told the package which disk that is. Logged as a warning: the value is being ignored, and an
     *    operator who set one and not the other should learn it from something other than a support case.
     *  - **A path recorded, a disk configured, and the file GONE.** Logged as an ERROR, because the row
     *    promises an archived document and the archive did not keep it. That is an incident.
     *
     * It renders rather than 404s in every one of them, deliberately. Refusing would lock an owner out of
     * their own invoice to make a point about an archive they do not control, and the document the package
     * can still produce is worth more to them than a dead link. What must not happen is the substitution
     * going UNRECORDED — so the divergence is loud in the log and invisible in the response, which is the
     * right way round: the reader gets their invoice, the operator gets the incident.
     */
    private function keptPdf(InvoiceRecord $invoice): ?string
    {
        $path = $invoice->pdf_path;

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $disk = Config::get('billing.invoices.pdf_disk');

        if (! is_string($disk) || trim($disk) === '') {
            Log::warning('An invoice records a kept PDF but billing.invoices.pdf_disk is not configured, so the stored file cannot be served and the document is being re-rendered instead.', [
                'invoice' => $invoice->number ?? $invoice->id,
                'pdf_path' => $path,
            ]);

            return null;
        }

        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            Log::error('An invoice records a kept PDF that is not on the configured disk. The document served is a fresh render and may differ from the one its recipient holds.', [
                'invoice' => $invoice->number ?? $invoice->id,
                'pdf_path' => $path,
                'disk' => $disk,
            ]);

            return null;
        }

        return $filesystem->get($path);
    }
}
