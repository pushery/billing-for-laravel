<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Consumer\AnnualTerms;
use Pushery\Billing\Contracts\ArrangesAnnualTerms;

/**
 * Annual terms where the package bills a subscription itself, under Mollie.
 *
 * Nothing renews such a subscription on its own: the package's engine opens every period, so it decides when a year
 * ends whether the next period is a month or a year ({@see LocalBillingEngine}). There is nothing to arrange ahead of
 * that moment, and this answers every arrangement with the subscription's own reference, which tells
 * {@see AnnualTerms} that the year is arranged. A further year booked is the mark the audit
 * log keeps, and the engine reads it when the year ends. A plan change scheduled for the end of the period is applied
 * when it comes due, as before, so it is not arranged here.
 */
final readonly class LocalAnnualTerms implements ArrangesAnnualTerms
{
    /** @param  string  $provider  the driver whose engine bills the subscriptions, as their rows name it */
    public function __construct(private string $provider) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function arrangeMonthlyAfterTerm(string $subscriptionReference, ?string $monthlyPrice, bool $replace = false): string
    {
        return $subscriptionReference;
    }

    public function arrangeFurtherYear(string $subscriptionReference, string $yearlyPrice, ?string $monthlyPrice): string
    {
        return $subscriptionReference;
    }

    public function arrangeChangeAtTermEnd(string $subscriptionReference, string $price, ?string $thenMonthlyPrice = null): ?string
    {
        return null;
    }

    public function release(string $arrangementReference): void {}
}
