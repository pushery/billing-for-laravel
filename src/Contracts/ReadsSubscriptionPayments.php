<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Exceptions\SubscriptionWithdrawalUnavailable;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\SubscriptionPeriodPayment;

/**
 * The payment that bought the period a subscriber is in, read where the active driver keeps it.
 *
 * ## Why it is a driver seam
 *
 * A withdrawal refunds the unused part of the period in progress, and the payment behind that period lives in a
 * different place on every driver. Stripe keeps it on the cycle's invoice. A driver the package bills itself
 * collects a cycle at its END, so nothing has been paid for the period in progress at all. A consumer that had to
 * work this out would be writing billing logic against each provider's internals.
 *
 * ## Null, a payment, or a refusal
 *
 * Null means no payment covers the period in progress, so nothing is owed back for it. A payment of zero is still a
 * payment: a trial the provider invoiced at nothing. A collection still in flight is neither, because money may yet
 * arrive for a period the subscriber is leaving, so it is refused rather than answered as null.
 */
interface ReadsSubscriptionPayments
{
    /** @throws SubscriptionWithdrawalUnavailable when the period's payment is still being collected */
    public function currentPeriodPayment(Subscription $subscription): ?SubscriptionPeriodPayment;
}
