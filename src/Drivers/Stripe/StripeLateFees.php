<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\LateFees;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * The Stripe LateFees: raises a pending invoice item on the owner's customer, so the fee rides on their
 * next invoice. The reference is passed as the idempotency key, so re-running the dunning advance for
 * the same owner at the same rung cannot add the fee twice. An owner with no Stripe customer yet is
 * skipped rather than created — there is nothing to bill a fee against.
 */
final readonly class StripeLateFees implements LateFees
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
    ) {}

    public function apply(Model $owner, Money $fee, string $reference, string $description): void
    {
        $customerId = $this->customers->find($owner);

        if ($customerId === null) {
            return;
        }

        $this->stripe->invoiceItems->create([
            'customer' => $customerId,
            'amount' => $fee->minorUnits,
            'currency' => strtolower($fee->currency),
            'description' => $description,
        ], ['idempotency_key' => $reference]);
    }
}
