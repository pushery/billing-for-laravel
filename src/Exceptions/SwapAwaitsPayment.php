<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A swap the provider accepted and holds until its charge is paid, so the subscription stays on its price until then.
 *
 * Stripe holds a change it charges for at once (another interval, the end of a trial, a free subscription moving to a
 * paid price) where the subscription is charged to a card or Link: the change applies only once the invoice is paid,
 * and a declined or unconfirmed payment leaves the subscription as it was. The swap returns nothing a caller could
 * read that from, so it says so by throwing. The invoice is named, and the page where the customer pays it where the
 * provider offers one.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class SwapAwaitsPayment extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $subscription,
        public readonly ?string $invoice,
        public readonly ?string $confirmationUrl,
        public readonly ?CarbonInterface $expiresAt,
    ) {
        parent::__construct($message);
    }

    /** $expiresAt is when the provider lets the held change go unless its invoice is paid by then, where it says. */
    public static function held(string $subscription, ?string $invoice, ?string $confirmationUrl, ?CarbonInterface $expiresAt = null): self
    {
        return new self(
            "The provider holds the swap of subscription {$subscription} until its invoice is paid, and the subscription stays on its price until then.",
            $subscription,
            $invoice,
            $confirmationUrl,
            $expiresAt,
        );
    }
}
