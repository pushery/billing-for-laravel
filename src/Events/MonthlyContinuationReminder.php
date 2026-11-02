<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\Money;

/**
 * A consumer's subscription has gone on monthly since its annual term ended, and another six months have passed.
 *
 * Dispatched by `billing:annual-terms:remind` every six months after the term ended, once each, so a customer who
 * stopped using the service hears again what it costs and how to end it. The application sends the mail, and this
 * event carries what the mail has to say. Provider-neutral, dispatched through Laravel's dispatcher.
 */
final readonly class MonthlyContinuationReminder implements BillingDomainEvent
{
    use SerializesModels;

    public function __construct(
        public Model $owner,
        public Subscription $subscription,
        public string $tierKey,
        /** What each month costs. */
        public Money $monthlyPrice,
        /** When the next month is charged, or null where the subscription names no period end. */
        public ?CarbonImmutable $nextChargeAt,
        /** Where the customer cancels, or null where the application named no plan screen. */
        public ?string $cancelUrl,
    ) {}
}
