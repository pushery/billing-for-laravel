<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SubscriptionState;

/**
 * What starting a subscription produced — which is not always the same shape.
 *
 * Two outcomes, and collapsing them would hide the difference that matters. A subscription that needs a
 * payment method sends the customer to the provider and is not a subscription yet; a trial the policy says
 * needs no payment method is granted on the spot and has nowhere to send anybody. A single "here is a URL"
 * return would make the caller invent a redirect for the second case, or check for an empty string and
 * guess what it meant.
 *
 * The state is what the caller shows. `Activating` is the optimistic post-checkout reading the account
 * screens already poll out of; `GenericTrial` is a standing that exists right now.
 */
final readonly class SubscriptionStart
{
    public function __construct(
        public SubscriptionState $state,
        /** Where to send the customer, or null when nothing has to happen in a browser. */
        public ?string $checkoutUrl = null,
        /** The payment the mandate will be granted by, so a caller can correlate its own record. */
        public ?string $paymentReference = null,
    ) {}

    /** Whether the customer has somewhere to go before this becomes a subscription. */
    public function needsRedirect(): bool
    {
        return $this->checkoutUrl !== null;
    }
}
