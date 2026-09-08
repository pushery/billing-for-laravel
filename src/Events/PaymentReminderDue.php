<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Pushery\Billing\Contracts\ArrearsRoster;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\ArrearsEntry;

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
 * Which relationship, and which merchant it is with. A customer holding five subscriptions cannot act on
 * "your payment is outstanding" — they do not know which one, and the message creates the support contact it
 * was meant to prevent. `$entry` carries the owner, the merchant and the date the arrears began.
 *
 * ## `$subscription` is nullable, and when it is null
 *
 * When the application has bound its own {@see ArrearsRoster}, because then
 * there IS no row in this package's table — that is the reason the roster seam exists. On the shipped
 * roster it is always present, so a listener written before the seam keeps working untouched; one written
 * against a custom roster reads `$entry` instead.
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
        public ArrearsEntry $entry,
        public int $daysLeft,
        public ?Subscription $subscription = null,
    ) {}
}
