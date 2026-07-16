<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Pushery\Billing\Contracts\UpcomingInvoice as UpcomingInvoiceContract;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\UpcomingInvoicePreview;
use Stripe\StripeClient;
use Throwable;

/**
 * A best-effort preview of a billable's next Stripe invoice — the ONE live provider read on the otherwise
 * column-authoritative subscription screen. Three properties matter and are all deliberate:
 *
 * 1. Null-tolerant. No customer / no live subscription → null with NO Stripe call. Any provider error →
 *    null too, so the screen leaves the line out rather than 500-ing on a connection/timeout/rate-limit
 *    (the catch is `Throwable`, not just Stripe's invalid-request).
 * 2. Cached per customer for a few minutes, so a screen render does not hit Stripe every time.
 * 3. A FAILURE is never cached. Cache::remember stores only a value the callback RETURNS; a thrown outage
 *    propagates out of remember uncached, so the next render retries Stripe instead of pinning the preview
 *    to "no estimate" for the whole TTL.
 */
final readonly class StripeUpcomingInvoice implements UpcomingInvoiceContract
{
    /** The preview is cached this long per customer — it is the one live read on the subscription screen. */
    private const int CACHE_TTL_SECONDS = 300;

    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
    ) {}

    public function preview(Model $billable): ?UpcomingInvoicePreview
    {
        $customerId = $this->customers->find($billable);
        $subscriptionId = $this->subscriptionReference($billable);

        // Stripe cannot preview an invoice from a customer alone — it rejects the call unless told WHAT to
        // preview. With no live subscription there is simply nothing upcoming; short-circuit before any read.
        if ($customerId === null || $subscriptionId === null) {
            return null;
        }

        try {
            return Cache::remember(
                "upcoming_invoice:{$customerId}",
                self::CACHE_TTL_SECONDS,
                fn (): UpcomingInvoicePreview => $this->fetch($customerId, $subscriptionId),
            );
        } catch (Throwable) {
            // A provider outage (or nothing to preview) — degrade to null rather than 500 the screen. The
            // failure left remember by throwing, so it was NOT cached and the next render retries.
            return null;
        }
    }

    private function fetch(string $customerId, string $subscriptionId): UpcomingInvoicePreview
    {
        $preview = $this->stripe->invoices->createPreview([
            'customer' => $customerId,
            'subscription' => $subscriptionId,
        ]);

        $timestamp = $preview->next_payment_attempt ?? $preview->period_end;

        return new UpcomingInvoicePreview(
            date: new DateTimeImmutable('@'.$timestamp),
            // The raw total (minor units, VAT included) — what the customer is actually charged, not
            // amount_due, which nets off any customer-balance credit and would understate the next invoice.
            amount: Money::of($preview->total, strtoupper($preview->currency)),
        );
    }

    /** The provider subscription reference from the billable's local subscription row, or null. */
    private function subscriptionReference(Model $billable): ?string
    {
        $subscription = Subscription::query()
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return $subscription?->provider_id;
    }
}
