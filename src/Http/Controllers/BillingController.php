<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\Support\SubscriptionReconciler;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        abort_unless($actor instanceof Model, 403);

        $owner = app(BillingEntityResolver::class)->ownerFor($actor);
        // The portal URL comes from the driver; validate it is an absolute http(s) URL before sending the
        // owner away, so a bad payload can never turn the portal link into a script or open-redirect target.
        $url = SafeExternalUrl::orNull(app(HostedPortal::class)->url($owner));

        abort_if($url === null, 404);

        return redirect()->away($url);
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

        abort_unless($actor instanceof Model, 403);

        $owner = app(BillingEntityResolver::class)->ownerFor($actor);

        try {
            app(SubscriptionReconciler::class)->syncFromProvider($owner);
        } catch (Throwable $e) {
            report($e);
        }

        // Flag the subscription screen as "activating": if the webhook has not landed yet the reconcile above
        // may not have recorded the subscription, so the screen shows a pending state and polls until it does.
        return redirect()->route('billing.account.subscription', ['activating' => 1]);
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

        abort_unless($actor instanceof Model, 403);

        $owner = app(BillingEntityResolver::class)->ownerFor($actor);
        $document = app(Invoices::class)->download($owner, $invoiceId);

        abort_if($document === null, 404);

        return response()->streamDownload(
            function () use ($document): void {
                echo $document->contents;
            },
            $document->filename,
            ['Content-Type' => $document->mimeType],
        );
    }
}
