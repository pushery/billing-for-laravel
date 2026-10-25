<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Enums\BalanceRenewalRefusal;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * The next period of a subscription paid from an internal balance was not taken.
 *
 * A listener tells the buyer, and the two outcomes need two different messages. With `$retryAt` the subscription is
 * `past_due`: access to the unpaid period is held back, the cure window has started, and the period is taken once the
 * balance covers it before the window runs out. Announced on the first refusal only; the reminders inside the window
 * are `PaymentReminderDue`, as for any subscription in arrears. Without `$retryAt` the subscription has ended, at the
 * end of the last period it paid for.
 */
final readonly class BalanceSubscriptionNotRenewed implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        /** What the period would have cost, or null where the subscription no longer states a price. */
        public ?Money $amount,
        public BalanceRenewalRefusal $reason,
        /** When the period is asked for again, or null where the subscription has ended. */
        public ?CarbonImmutable $retryAt,
    ) {}
}
