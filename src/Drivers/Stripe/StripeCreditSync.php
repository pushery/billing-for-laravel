<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\CreditSync;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * Pushes a package credit change onto the Stripe customer balance, so the credit actually reduces the
 * customer's next Stripe invoice instead of only sitting in the local ledger.
 *
 * SIGN. Stripe's customer balance is the opposite of the package's: a POSITIVE Stripe balance is money the
 * customer OWES (added to the next invoice), a NEGATIVE balance is credit (subtracted from it). The package
 * ledger is positive-is-credit. So a package credit of +500 is pushed to Stripe as -500, and a clawed-back
 * credit of -500 as +500 — this inverts the delta.
 *
 * IDEMPOTENCY. The reference is sent as the Idempotency-Key in the request OPTIONS (the third argument),
 * never in the params — Cashier's applyBalance() merges options into the params, so a key passed there
 * becomes a request-body field and a network retry double-credits. Calling the SDK directly, like
 * StripeLateFees, is what puts the key in the header where it dedups. A customer with no Stripe customer
 * yet is skipped: there is no balance to move, and the local ledger still holds the credit.
 */
final readonly class StripeCreditSync implements CreditSync
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
    ) {}

    public function push(Model $owner, Money $signedDelta, string $reference): void
    {
        if ($signedDelta->isZero()) {
            return;
        }

        $customerId = $this->customers->find($owner);

        if ($customerId === null) {
            return;
        }

        $this->stripe->customers->createBalanceTransaction($customerId, [
            // Inverted: package positive-is-credit → Stripe negative-is-credit.
            'amount' => -$signedDelta->minorUnits,
            'currency' => strtolower($signedDelta->currency),
            'description' => 'Billing credit adjustment',
        ], ['idempotency_key' => 'credit:'.$reference]);
    }
}
