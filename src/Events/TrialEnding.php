<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\IdentifiesCustomer;

/**
 * A customer's free trial is about to end (the neutral form of the provider's "trial will end" signal).
 *
 * It fires ahead of the trial's end — the provider sends it a few days out — so the owner can add a payment
 * method before the first charge, not discover the lapse after it. It carries the trial's end date for the
 * reminder, and the subscription reference so the notice is sent once per trial end, not once per redelivery.
 */
final readonly class TrialEnding implements BillingDomainEvent, IdentifiesCustomer
{
    public function __construct(
        public string $customerReference,
        public string $subscriptionReference,
        public CarbonInterface $trialEndsAt,
    ) {}
}
