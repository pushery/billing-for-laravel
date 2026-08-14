<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Psr\Log\LoggerInterface;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\SubscriptionItem;

/**
 * The Stripe proration strategy. Stripe books the proration on its own side when a swap executes, so
 * applySwap does nothing — but the account hub still wants to show the customer what the change will
 * cost BEFORE they commit. previewSwap asks Stripe to preview the invoice that a swap to the new plan
 * would raise (create_preview with subscription_details.items = the current item repriced), and returns
 * the net amount due after proration credits.
 *
 * It is deliberately null-tolerant everywhere the preview cannot be computed — no remote price on the
 * target plan, no Stripe customer, no local subscription row, or any Stripe read failure — so the
 * screen degrades to "no estimate" rather than showing a wrong or misleading figure.
 *
 * "Any read failure" means any: the catch is on `ApiErrorException`, the root of Stripe's hierarchy. It was
 * on `InvalidRequestException` once, which sounds close and is not — a timeout, a rotated key, a 403 and a
 * 5xx all descend from `ApiErrorException` DIRECTLY, so the four failures worth degrading for were the four
 * that escaped, while the one Stripe heals by itself (429, which does descend from it) was caught.
 *
 * A degraded preview is LOGGED. Silence would trade a broken screen for an invisible outage, and a rotated
 * key is not transient — it stays until somebody notices.
 */
final readonly class StripeProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private StripeClient $stripe,
        private StripeSubscriptionItems $items,
        private StripeCustomerRegistry $customers,
        private ?LoggerInterface $log = null,
    ) {}

    /**
     * Where the warning goes.
     *
     * Resolved rather than injected, because this class is public surface a consumer may construct itself
     * and a new required argument would be a fatal error in their code — the same reason the ledger resolves
     * its dispatcher this way.
     */
    private function logger(): LoggerInterface
    {
        return $this->log ?? Container::getInstance()->make(LoggerInterface::class);
    }

    public function previewSwap(Model $billable, Plan $newPlan): ?Money
    {
        $price = $newPlan->providerPriceId;
        $customerId = $this->customers->find($billable);
        $subscriptionId = $this->subscriptionReference($billable);

        if ($price === null || $customerId === null || $subscriptionId === null) {
            return null;
        }

        try {
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
            $base = $this->items->base($subscription);

            if (! $base instanceof SubscriptionItem) {
                return null;
            }

            // Reprice the tier item only. A metered component is left out of the override, so the preview
            // prices the swap against the subscription as it actually stands.
            $preview = $this->stripe->invoices->createPreview([
                'customer' => $customerId,
                'subscription' => $subscriptionId,
                'subscription_details' => [
                    'items' => [['id' => $base->id, 'price' => $price]],
                    'proration_behavior' => 'create_prorations',
                ],
            ]);
        } catch (ApiErrorException $e) {
            // EVERY Stripe read failure, which is what the class docblock has always promised and what only
            // `InvalidRequestException` used to deliver. A timeout, a rotated key, a 403 and a 5xx do not
            // descend from it -- they walked past this line and out of the method, and the caller is a
            // Livewire action behind a button. One button took the whole screen down while the text written
            // for exactly this case sat next to it, never shown.
            //
            // Logged rather than swallowed, and that is the half that keeps this honest. A 401 is not
            // transient: it stays until somebody notices. Degrading silently would trade a broken screen for
            // an invisible outage, which is the same bargain this package refuses everywhere else -- an
            // absence must not read as an answer. The screen stays usable AND the operator finds out.
            // @rate-limit-deliberate: a 429 lands here too, and swallowing it is right at this one site.
            // The caller is a Livewire action behind a button, on a screen whose entire job is to show an
            // estimate; letting it through would take that screen down over a condition that clears in a
            // second, and the "no estimate" state is already built and translated. Nothing is written and
            // nothing is marked done, so the retry is the customer pressing the button again -- and unlike
            // every other catch in this driver that meets a 429, it is logged rather than silent.
            //
            // The number that used to be here said eight; there were ten, and the sentence was written after
            // the tenth existed. A count of code in prose is a fact with a clock on it -- the shape says
            // what it means and cannot go stale.
            $this->logger()->warning('Could not preview a subscription swap; showing no estimate.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return Money::of($preview->amount_due, strtoupper($preview->currency));
    }

    public function applySwap(Model $billable, Plan $newPlan): void
    {
        // Stripe books the proration itself when the swap executes against it; nothing to apply locally.
    }

    /** The provider subscription reference from the billable's local subscription row, or null. */
    private function subscriptionReference(Model $billable): ?string
    {
        $subscription = Subscription::query()
            ->forOwner($billable)
            ->forMerchant(null)
            ->ofDefaultType()
            ->latest('id')
            ->first();

        return $subscription?->provider_id;
    }
}
