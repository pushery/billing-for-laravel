<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Invoicing\InvoiceDocumentRenderer;
use Pushery\Billing\Invoicing\KeptInvoicePdf;
use Pushery\Billing\Invoicing\LocalInvoices;
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
        $reader = $this->invoiceReader();
        $document = $reader->download($owner, $invoiceId);

        // A provider that hosts its own PDFs (Stripe) answers here. For an invoice it does not host, the
        // package serves its own stored one. A reader that IS the package's own table has already looked at
        // that row, kept file included, so its answer is the whole answer. A foreign invoice and an absent one
        // are both a 404, so one owner can neither pull another's document nor learn that it exists by
        // guessing an id.
        if (! $document instanceof InvoiceDownload && ! $reader instanceof LocalInvoices) {
            $document = $this->renderLocalInvoice($owner, $invoiceId);
        }

        if (! $document instanceof InvoiceDownload) {
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
     * Render one of the package's OWN stored invoices as a document — the local path for an invoice the
     * provider does not host. The invoice is looked up among the owner's own rows, so a row belonging to
     * another owner reads as absent: a shared id space can neither leak one owner's document to another nor
     * confirm that it exists.
     *
     * The KEPT file wins over a fresh render where there is one, for the reason `KeptInvoicePdf` gives.
     *
     * Without a kept file and without a PDF renderer, the shipped default, there is no document to hand over,
     * and the route answers 404 as it does for an invoice that is not there. A missing optional dependency is
     * not an error page.
     */
    private function renderLocalInvoice(Model $owner, string $invoiceId): ?InvoiceDownload
    {
        $invoice = Container::getInstance()->make(LocalInvoices::class)->record($owner, $invoiceId);

        if ($invoice === null) {
            return null; // not this owner's, or no invoice at all → 404 either way
        }

        $number = $invoice->number ?? (string) $invoice->id;
        $kept = Container::getInstance()->make(KeptInvoicePdf::class)->contents($invoice);

        if ($kept !== null) {
            return new InvoiceDownload("invoice-{$number}.pdf", $kept);
        }

        $renderer = Container::getInstance()->make(InvoiceDocumentRenderer::class);

        if (! $renderer->rendersPdf()) {
            return null;
        }

        return new InvoiceDownload("invoice-{$number}.pdf", $renderer->pdf($invoice));
    }

    /**
     * The invoice reader the driver binds, typed as the contract it is: a provider's reader under Stripe, the
     * package's own table under a local-engine driver, and the download treats the two differently.
     */
    private function invoiceReader(): Invoices
    {
        return Container::getInstance()->make(Invoices::class);
    }
}
