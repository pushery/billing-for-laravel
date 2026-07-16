<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ProrationStrategy;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\Plan;
use Stripe\Exception\InvalidRequestException;
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
 */
final readonly class StripeProrationStrategy implements ProrationStrategy
{
    public function __construct(
        private StripeClient $stripe,
        private StripeSubscriptionItems $items,
        private StripeCustomerRegistry $customers,
    ) {}

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
        } catch (InvalidRequestException) {
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
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->where('type', 'default')
            ->latest('id')
            ->first();

        return $subscription?->provider_id;
    }
}
