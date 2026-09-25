<?php

declare(strict_types=1);

namespace Pushery\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\ValueObjects\Money;

/**
 * Less came back from a merchant than the reversal asked for.
 *
 * The buyer got the whole refund, and the provider took back less of the merchant's share than the reversal
 * asked for: proportionally, where a fee with a fixed part owes more, or out of a balance that held too
 * little. Unless something puts the difference on the merchant, the platform carries it.
 *
 * Whether the merchant owes it is a matter of the operator's terms with its merchants, so the package books
 * nothing and says how much. A consumer whose terms put it on the merchant charges it with
 * `MerchantSubLedger::chargeShortfall()`.
 *
 * Dispatched once per refund attempt, after the reversal is committed, and only where the provider was asked
 * to reverse. On a separate transfer nothing was asked, and the whole share is still with the merchant.
 */
final readonly class MerchantReversalCameBackShort implements BillingDomainEvent
{
    public function __construct(
        public Model $merchant,
        public string $provider,
        public string $chargeReference,
        /** How much less came back than the reversal asked for. */
        public Money $shortfall,
        public ReversalCause $cause,
    ) {}
}
