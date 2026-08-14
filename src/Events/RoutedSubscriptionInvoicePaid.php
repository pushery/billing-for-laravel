<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * A subscription cycle was invoiced and paid — the moment a routed subscription's commission becomes a fact.
 *
 * ## Why this is its own event beside PaymentSucceeded
 *
 * The same provider message already maps to `PaymentSucceeded` (dunning recovery reads it) and to an
 * invoice snapshot (the document is persisted). Both are about the PLATFORM's own relationship with the
 * buyer, and both fire for every payment there is.
 *
 * This one exists to be narrow. Its handler reaches the provider, so an effect hung on `PaymentSucceeded`
 * would make three provider calls for every payment on every install — including the overwhelming majority
 * that route nothing anywhere. Carrying the subscription reference is what lets the handler ask the local
 * subscription first and stop before any of that.
 *
 * ## Why the subscription reference, and not a merchant
 *
 * The mapper cannot answer who a routed invoice pays: the delivered invoice payload carries no
 * `transfer_data` and no account. It carries the subscription, and the subscription is where this package
 * already recorded the merchant when the cycle began. Resolving the merchant here would mean guessing, and
 * carrying nothing would mean the handler has to fetch before it can decide whether to fetch.
 */
final readonly class RoutedSubscriptionInvoicePaid implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        /** The provider's invoice id — the cycle, and the dedup key the ledger row is written under. */
        public string $invoiceReference,
        /** The provider's subscription id, so the handler can ask whether this one routes at all. */
        public string $subscriptionReference,
    ) {}
}
