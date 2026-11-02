<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * A consumer's annual term turns into a monthly contract soon, and the first monthly charge is about two weeks away.
 *
 * Dispatched once per term by `billing:annual-terms:remind`. The application sends the mail, and this event carries
 * what the mail has to say. Provider-neutral, dispatched through Laravel's dispatcher.
 */
final readonly class MonthlyContinuationBeginsSoon implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Subscription $subscription,
        public string $tierKey,
        /** When the annual term ends and the first month is charged. */
        public CarbonImmutable $firstChargeAt,
        /** The last calendar day a cancellation can be declared before that charge, in the owner's time zone, as `Y-m-d`. */
        public string $lastCancellationDay,
        /** What each month costs. */
        public Money $monthlyPrice,
        /** Where the customer cancels, or null where the application named no plan screen. */
        public ?string $cancelUrl,
    ) {}
}
