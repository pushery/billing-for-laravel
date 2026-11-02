<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * An annual term ends soon: the year a customer bought, or a further one they booked.
 *
 * Dispatched once per term by `billing:annual-terms:remind`, five weeks and a few days before the term ends, so the
 * customer can decide in time. For a consumer it is the reminder due before the year turns into a contract that runs
 * from month to month; for a business it is a courtesy before the next year begins. The application sends the mail,
 * and this event carries what the mail has to say. Provider-neutral, dispatched through Laravel's dispatcher.
 */
final readonly class AnnualTermEndingSoon implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Subscription $subscription,
        public string $tierKey,
        public CustomerType $customerType,
        /** When the term ends. A cancellation declared before then ends the subscription at this moment. */
        public CarbonImmutable $endsAt,
        /** The last calendar day a cancellation can be declared, in the owner's time zone where one is bound, as `Y-m-d`. */
        public string $lastCancellationDay,
        /** What a consumer's subscription goes on at each month after the term, or null for a business, whose renews for a year. */
        public ?Money $monthlyPrice,
        /** Where the customer cancels, or null where the application named no plan screen. */
        public ?string $cancelUrl,
        /** Where the customer books a further year, or null where none is offered. */
        public ?string $rebookUrl,
    ) {}
}
