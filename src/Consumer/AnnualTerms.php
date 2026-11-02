<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\ArrangesAnnualTerms;
use Pushery\Billing\Contracts\MerchantCatalog;
use Pushery\Billing\Contracts\PlanCatalog;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\CustomerType;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\LocalAnnualTerms;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Plan;
use RuntimeException;

/**
 * What a consumer can still decide about an annual term before it ends.
 *
 * ## A further year, booked expressly
 *
 * A consumer's annual term goes on monthly once it ends. A further year is offered in the last 60 days of the term,
 * once, and only as the customer's express order in the wording they confirmed: never by staying silent. The order is
 * written to the audit log with its wording and the term it extends, and that mark is also how the reminders learn that
 * this term is followed by a year rather than by a month.
 *
 * ## The monthly price, for a subscription sold before it was named
 *
 * Such a subscription goes on at a twelfth of the price of its year. Until the year ends the customer may choose the
 * tier's monthly price instead, expressly, or a further year. A customer who chooses nothing keeps the twelfth.
 *
 * ## Where it works
 *
 * On a driver that binds {@see ArrangesAnnualTerms}, for a consumer's annual subscription that is running and not ending.
 * A business's annual subscription renews for a year by itself, so it is offered neither.
 */
final readonly class AnnualTerms
{
    /** Days before the end of a term in which a further year is offered. */
    public const int FURTHER_YEAR_DAYS = 60;

    /** The audit type of a further year booked, which the reminders read as well. */
    public const string FURTHER_YEAR_BOOKED = 'annual_term.further_year_booked';

    public const string MONTHLY_PRICE_CHOSEN = 'annual_term.monthly_price_chosen';

    public function __construct(
        private PlanCatalog $plans,
        private CustomerTypes $types,
        private BillingEventLog $log,
        private MerchantCatalog $catalogs,
    ) {}

    /** Whether a further year can be booked on this subscription now. */
    public function offersFurtherYear(Model $owner, Subscription $subscription): bool
    {
        $end = $this->termEnd($owner, $subscription);
        $now = CarbonImmutable::now();

        return $end instanceof CarbonImmutable && $this->arranges($subscription)
            && $now->greaterThanOrEqualTo($end->subDays(self::FURTHER_YEAR_DAYS))
            && $this->sellsAt($subscription, (string) $subscription->tier_key, BillingInterval::Year)
            && ! $this->furtherYearBooked($owner, $subscription, $end);
    }

    /**
     * Book a further year, as the customer's express order in the wording they confirmed. `$actor` is the person who
     * placed it where that is not the owner, a member of the owner's team for one.
     *
     * @throws InvalidArgumentException when no further year is offered on this subscription now, or the wording is missing
     * @throws RuntimeException when the driver could not arrange it
     */
    public function bookFurtherYear(Model $owner, Subscription $subscription, string $statement, ?Model $actor = null): void
    {
        if (trim($statement) === '' || ! $this->offersFurtherYear($owner, $subscription)) {
            throw new InvalidArgumentException('A further year is offered once, in the last '.self::FURTHER_YEAR_DAYS.' days of a '
                .'consumer\'s annual term, and booked in the wording the customer confirmed.');
        }

        $tierKey = (string) $subscription->tier_key;
        $monthly = $subscription->monthly_terms_at === null ? null : $this->plansFor($subscription)->providerPriceFor($tierKey, BillingInterval::Month);
        $arranged = $this->arrangements()->arrangeFurtherYear((string) $subscription->provider_id,
            (string) $this->plansFor($subscription)->providerPriceFor($tierKey, BillingInterval::Year), $monthly);

        $this->arranged($subscription, $arranged);

        $end = $this->termEnd($owner, $subscription) ?? CarbonImmutable::now();

        $this->log->record(self::FURTHER_YEAR_BOOKED, $owner, [
            'subscription' => $this->keyOf($subscription),
            'day' => $end->format('Y-m-d'),
            'until' => $end->addYear()->format('Y-m-d'),
            'statement' => $statement,
        ], AuditSource::Customer, $actor ?? $owner);
    }

    /** Whether the customer of a subscription sold before monthly terms can still choose the tier's monthly price. */
    public function offersMonthlyPrice(Model $owner, Subscription $subscription): bool
    {
        return $subscription->monthly_terms_at === null && $this->arranges($subscription)
            && $this->termEnd($owner, $subscription) instanceof CarbonImmutable
            && $this->sellsAt($subscription, (string) $subscription->tier_key, BillingInterval::Month);
    }

    /**
     * Choose the tier's monthly price for the time after the year, as the customer's express answer in the wording they
     * confirmed. From then on the subscription counts as sold under monthly terms.
     *
     * @throws InvalidArgumentException when the choice is not offered on this subscription, or the wording is missing
     * @throws RuntimeException when the driver could not arrange it
     */
    public function chooseMonthlyPrice(Model $owner, Subscription $subscription, string $statement): void
    {
        if (trim($statement) === '' || ! $this->offersMonthlyPrice($owner, $subscription)) {
            throw new InvalidArgumentException('The tier\'s monthly price is offered to a subscription sold before its terms named '
                .'one, until its year ends, and chosen in the wording the customer confirmed.');
        }

        $arranged = $this->arrangements()->arrangeMonthlyAfterTerm((string) $subscription->provider_id,
            $this->plansFor($subscription)->providerPriceFor((string) $subscription->tier_key, BillingInterval::Month), replace: true);

        $this->arranged($subscription, $arranged, CarbonImmutable::now());

        $this->log->record(self::MONTHLY_PRICE_CHOSEN, $owner, [
            'subscription' => $this->keyOf($subscription),
            'statement' => $statement,
        ], AuditSource::Customer, $owner);
    }

    /**
     * When the subscription's annual term ends, or null where it is not a consumer's annual term that is running, has
     * not ended and is not ending. A trial is not the term: the year begins where the trial ends.
     */
    public function termEnd(Model $owner, Subscription $subscription): ?CarbonImmutable
    {
        $tierKey = $subscription->tier_key;
        $end = $subscription->current_period_end;

        if ($tierKey === null || ($subscription->provider_id === null && ! $this->billedByTheEngine($subscription)) || $subscription->ends_at !== null
            || ! $end instanceof DateTimeInterface || $subscription->status !== 'active'
            || ($subscription->billing_interval ?? $this->plansFor($subscription)->planFor($tierKey)?->interval) !== BillingInterval::Year
            || ($subscription->customer_type ?? $this->types->of($owner)) !== CustomerType::Consumer) {
            return null;
        }

        $end = CarbonImmutable::instance($end);

        return $end->isFuture() ? $end : null;
    }

    /** Whether a further year was booked already for the term that ends on this day. */
    public function furtherYearBooked(Model $owner, Subscription $subscription, CarbonImmutable $end): bool
    {
        $key = $this->keyOf($subscription);
        $date = $end->format('Y-m-d');

        return BillingEvent::model()::query()
            ->where('type', self::FURTHER_YEAR_BOOKED)
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->get()
            ->contains(static fn (BillingEvent $event): bool => ($event->payload['subscription'] ?? null) === $key
                && ($event->payload['day'] ?? null) === $date);
    }

    /**
     * Whether the billing driver in use arranges annual terms, and for the provider this subscription is at, which every
     * offer here needs. A subscription an earlier driver started is billed where it began.
     */
    public function arranges(Subscription $subscription): bool
    {
        $container = Container::getInstance();

        return $container->bound(ArrangesAnnualTerms::class)
            && $container->make(ArrangesAnnualTerms::class)->provider() === $subscription->provider;
    }

    /** The catalog a subscription is priced from: its merchant's for a subscription at a merchant, the platform's otherwise. */
    public function plansFor(Subscription $subscription): PlanCatalog
    {
        $scope = MerchantScope::fromUid((string) $subscription->merchant_uid);

        return $scope->isPlatform() ? $this->plans : $this->catalogs->planCatalog($scope);
    }

    /**
     * Whether the tier is sold at this period where the subscription is billed: at a provider's price, or, where the
     * package bills the subscription itself, at a price the tier shows, which is all its engine charges.
     */
    private function sellsAt(Subscription $subscription, string $tierKey, BillingInterval $interval): bool
    {
        $plans = $this->plansFor($subscription);

        return $plans->providerPriceFor($tierKey, $interval) !== null
            || ($this->billedByTheEngine($subscription) && $plans->planFor($tierKey, $interval) instanceof Plan);
    }

    /**
     * Whether the package's own engine bills the subscription and decides what follows its year when the year ends. Such
     * a subscription holds no reference at a provider, and nothing is arranged there for it.
     */
    private function billedByTheEngine(Subscription $subscription): bool
    {
        return $this->arranges($subscription) && $this->arrangements() instanceof LocalAnnualTerms;
    }

    private function arrangements(): ArrangesAnnualTerms
    {
        return Container::getInstance()->make(ArrangesAnnualTerms::class);
    }

    /** @throws RuntimeException when the driver could not arrange the subscription */
    private function arranged(Subscription $subscription, ?string $arrangement, ?CarbonImmutable $monthlyTermsAt = null): void
    {
        if ($arrangement === null) {
            throw new RuntimeException('The billing driver could not arrange the annual term of subscription '.$this->keyOf($subscription).'.');
        }

        $subscription->forceFill(array_filter([
            // The engine keeps no schedule at a provider: it reads a further year from the log when the year ends.
            'provider_schedule_id' => $this->billedByTheEngine($subscription) ? null : $arrangement,
            'monthly_terms_at' => $monthlyTermsAt,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    /** The subscription's key as text, for a log entry and a cache key. */
    private function keyOf(Subscription $subscription): string
    {
        $key = $subscription->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
