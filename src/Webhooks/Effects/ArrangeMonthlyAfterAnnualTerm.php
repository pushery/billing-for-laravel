<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Consumer\CustomerTypes;
use Pushery\Billing\Contracts\ArrangesAnnualTerms;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Arranges a consumer's annual subscription to go on at the tier's monthly price once its year ends.
 *
 * A consumer may not be held to a further year by staying silent, so the year that was bought ends in a contract that
 * runs from month to month, at the monthly price the plan names. A business's annual subscription renews for a year,
 * and this leaves it alone.
 *
 * ## From the event, not from the row
 *
 * Every effect runs as a job of its own, so the plan sync may not have written the row yet when this runs, and a
 * subscription paid at checkout may raise no second event. So the event decides: a running subscription, not ending
 * and not paused, held by no schedule, sold yearly. The customer type is the one the row froze where the row exists,
 * and otherwise the one the sync is about to freeze, read the same way.
 *
 * A subscription in its trial is arranged once the trial ends, with the event that says so: during the trial its
 * current period is the trial, and a schedule written then would take the trial for the year and go on monthly after
 * it.
 *
 * ## At which monthly price
 *
 * At the tier's own, for a subscription sold under terms that named it. A subscription that began before such terms, a
 * row whose `monthly_terms_at` is null, goes on at a twelfth of the price of its year: the tier's monthly price is
 * usually higher, and a higher price agreed by staying silent would not bind the customer.
 *
 * ## A merchant's subscription
 *
 * Every checkout the package opens runs on the platform's account, so a subscription a buyer holds at a merchant lives
 * there as well and routes its money on to the merchant. It goes on at the monthly price of the merchant's tier, from the
 * merchant's catalog, and the driver keeps its routing in every phase.
 *
 * ## What it leaves alone
 *
 * A subscription on a merchant's own account, which only an application that runs direct charges starts: this reaches
 * the platform's account. A tier with no monthly price: there is nothing to go on at, and the log says so, because
 * that year will renew as a year. A driver that binds no {@see ArrangesAnnualTerms}.
 */
final readonly class ArrangeMonthlyAfterAnnualTerm
{
    public function __construct(
        private CustomerDirectory $directory,
        private PlanCatalog $plans,
        private MerchantCatalog $catalogs,
        private CustomerTypes $types,
    ) {}

    public function __invoke(SubscriptionStateChanged $event): void
    {
        $tierKey = $event->tierKey;
        $reference = $event->subscriptionReference;
        $container = Container::getInstance();

        if ($tierKey === null || $reference === null || $event->scheduleReference !== null || $event->merchantAccountReference !== null
            || $event->state !== SubscriptionState::Active
            || ! $container->bound(ArrangesAnnualTerms::class)) {
            return;
        }

        $plans = $event->merchant instanceof MerchantScope && ! $event->merchant->isPlatform() ? $this->catalogs->planCatalog($event->merchant) : $this->plans;

        if (($event->interval ?? $plans->planFor($tierKey)?->interval) !== BillingInterval::Year) {
            return;
        }

        $row = Subscription::model()::query()->where('provider_id', $reference)->first();

        if ($row instanceof Subscription && $row->provider_schedule_id !== null) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model || ($row->customer_type ?? $this->types->of($owner)) !== CustomerType::Consumer) {
            return;
        }

        // A subscription sold before its terms named a monthly price goes on at a twelfth of the price of its year,
        // which the driver works out: the monthly price of the tier would be a higher price agreed by staying silent.
        $soldBeforeMonthlyTerms = $row instanceof Subscription && $row->monthly_terms_at === null;
        $monthly = $soldBeforeMonthlyTerms ? null : $plans->providerPriceFor($tierKey, BillingInterval::Month);

        if (! $soldBeforeMonthlyTerms && $monthly === null) {
            Log::error('billing: a tier is sold yearly to a consumer and has no monthly price to go on at, so its year will '
                .'renew as a year', ['tier' => $tierKey, 'subscription' => $reference]);

            return;
        }

        $arranged = $container->make(ArrangesAnnualTerms::class)->arrangeMonthlyAfterTerm($reference, $monthly);

        if ($arranged !== null && $row instanceof Subscription) {
            $row->forceFill(['provider_schedule_id' => $arranged])->save();
        }
    }
}
