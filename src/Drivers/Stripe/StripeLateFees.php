<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Marketplace\MarketplaceSaleContext;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * The Stripe LateFees: raises a pending invoice item on the owner's customer, so the fee rides on their
 * next invoice. The reference is passed as the idempotency key, so re-running the dunning advance for
 * the same owner at the same rung cannot add the fee twice. An owner with no Stripe customer yet is
 * skipped rather than created — there is nothing to bill a fee against.
 *
 * ## Not taxed, not discounted, and on the subscription in arrears
 *
 * A late fee compensates the delay and buys nothing, so no VAT reaches it ({@see TaxExemptionReason::NotConsideration}
 * carries the sources). Under the provider tax mode Stripe Tax computes the tax of the subscription's invoices, and an
 * item without a tax code takes the account's preset one, which taxes it like the plan. The item therefore names
 * Stripe's code for a nontaxable line. Outside that mode Stripe computes no tax on the invoice, and the item stays
 * as it was.
 *
 * An item is discountable by default, so a coupon on the subscription would reduce the fee as well; a coupon
 * discounts what was sold, and the fee sold nothing. And without a subscription the item lands on the next invoice
 * of any of the customer's subscriptions, which is not always the one in arrears.
 */
final readonly class StripeLateFees implements LateFees
{
    /** Stripe's tax code for a line that is not taxable at all. */
    private const string NONTAXABLE = 'txcd_00000000';

    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private MarketplaceSaleContext $context,
    ) {}

    public function apply(Model $owner, Money $fee, string $reference, string $description, ?Subscription $subscription = null): void
    {
        $customerId = $this->customers->find($owner);

        if ($customerId === null) {
            return;
        }

        $item = [
            'customer' => $customerId,
            'amount' => $fee->minorUnits,
            'currency' => strtolower($fee->currency),
            'description' => $description,
            'discountable' => false,
        ];

        $stripeSubscription = $this->stripeSubscriptionOf($subscription);

        if ($stripeSubscription !== null) {
            $item['subscription'] = $stripeSubscription;
        }

        if ($this->context->providerTax()) {
            $item['tax_code'] = self::NONTAXABLE;
            $item['tax_behavior'] = 'inclusive';
        }

        $this->stripe->invoiceItems->create($item, ['idempotency_key' => $reference]);
    }

    /** The Stripe id of the subscription in arrears, where it is one this driver created. */
    private function stripeSubscriptionOf(?Subscription $subscription): ?string
    {
        if (! $subscription instanceof Subscription || $subscription->provider !== 'stripe') {
            return null;
        }

        $id = $subscription->provider_id;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
