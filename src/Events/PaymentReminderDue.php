<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Models\Subscription;

/**
 * A subscription in arrears is inside its cure window, and today's reminder is due.
 *
 * ## Why this is an event and not a mail
 *
 * The package knows a payment is late and how long the customer still has. It does not know how this
 * consumer talks to their customers — mail, in-app, push, or a support queue — and picking one would make
 * the package a notification product. The consumer listens and decides.
 *
 * ## What a listener MUST put in the message
 *
 * Which subscription, and which merchant it belongs to. A customer holding five subscriptions cannot act on
 * "your payment is outstanding" — they do not know which one, and the message creates the support contact it
 * was meant to prevent. The subscription is carried whole so a listener can name the merchant from it.
 *
 * And the message is not a warning that access is at risk: access is ALREADY withdrawn for this merchant.
 * Arrears withdraw the relationship's surfaces immediately, and the window that follows is a chance to cure,
 * not a grace period. A listener that writes "your access may be suspended" describes a state that has
 * already passed.
 *
 * `$daysLeft` is whole days remaining before the subscription expires for good — zero on the final day,
 * never negative.
 */
final readonly class PaymentReminderDue implements BillingDomainEvent
{
    public function __construct(
        public Subscription $subscription,
        public int $daysLeft,
    ) {}
}
