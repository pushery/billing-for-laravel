<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A driver that arranges ahead of time how a consumer's annual subscription goes on once the year paid for ends: at
 * the tier's monthly price, and not for another year.
 *
 * The arrangement has to stand before the year ends. A provider that renews a subscription on its own does so at the
 * moment the period ends, for another period of the same length, and a change made after that moment is too late:
 * the customer has been charged for a year they did not agree to.
 */
interface ArrangesAnnualTerms
{
    /**
     * Arrange that the subscription continues monthly once its current annual period ends: at `$monthlyPrice`, or, where
     * that is null, at a twelfth of the price of the year, rounded down.
     *
     * A twelfth is what a subscription sold before its monthly price was named goes on at: a higher price agreed by
     * staying silent would not bind the customer.
     *
     * An arrangement that holds the subscription already is the answer, unless `$replace` asks for it to be taken off and
     * made anew, as a customer's choice of another monthly price does.
     *
     * @return string|null the reference of the arrangement, or null where the subscription cannot be arranged this way
     */
    public function arrangeMonthlyAfterTerm(string $subscriptionReference, ?string $monthlyPrice, bool $replace = false): ?string;

    /**
     * Arrange that the subscription goes on for a further year at `$yearlyPrice` once its current annual period ends, and
     * after that year monthly, as {@see arrangeMonthlyAfterTerm()} does.
     *
     * The customer's express order: a further year is never arranged by staying silent.
     *
     * @return string|null the reference of the arrangement, or null where the subscription cannot be arranged this way
     */
    public function arrangeFurtherYear(string $subscriptionReference, string $yearlyPrice, ?string $monthlyPrice): ?string;

    /**
     * Arrange a plan change the customer scheduled for the end of the current period: the period as it stands, then
     * `$price` for one period, then, where it is given, `$thenMonthlyPrice` for one month, after which the subscription
     * goes on as it then stands.
     *
     * A provider that renews on its own renews at the moment the period ends, so a change applied after that moment
     * would come after an invoice for another period of the old price.
     *
     * @return string|null the reference of the arrangement, or null where the subscription cannot be arranged this way
     */
    public function arrangeChangeAtTermEnd(string $subscriptionReference, string $price, ?string $thenMonthlyPrice = null): ?string;

    /** The provider whose subscriptions it arranges, as the `provider` column of a subscription names it. */
    public function provider(): string;

    /** Take an arrangement off its subscription, so the subscription goes on as it stands. Taking it off twice is no error. */
    public function release(string $arrangementReference): void;
}
