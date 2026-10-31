<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\BillingInterval;

/**
 * The text a hosted checkout shows beside its order button.
 *
 * § 312j Abs. 2 BGB asks a seller to give a consumer the essential features of the service, the total price for each
 * billing period, the term and the conditions of cancellation immediately before the order is placed. On a hosted
 * checkout that moment is on the provider's page, so the text travels with the session: the Stripe checkout shows it
 * beside its order button, up to 1200 characters.
 *
 * The shipped binding reads `billing.checkout.submit_message`. Bind an implementation of your own to word the text in
 * code, for example in the language the buyer reads, which an implementation can take from the billable.
 */
interface OrderButtonText
{
    /**
     * The text beside the order button of a subscription to $tierKey at $interval, or null for none. The total price of
     * each billing period differs between a tier's intervals, so a null interval is the tier's default one.
     */
    public function forSubscription(Model $billable, string $tierKey, ?BillingInterval $interval = null): ?string;

    /** The text beside the order button of the one-time purchase of $addonKey, or null for none. */
    public function forAddon(Model $billable, string $addonKey): ?string;
}
