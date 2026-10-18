<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\ListsContracts;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\CatalogLabel;
use Pushery\Billing\Support\TierInterval;
use Pushery\Billing\ValueObjects\ContractChoice;

/**
 * The contracts the package offers by default: every subscription of the person that is running and not already
 * ending.
 *
 * Running means active, in a trial, past due or paused, without an end and not terminated. A subscription that
 * already has an end is not offered again, because declaring its cancellation a second time would declare nothing
 * new. Each is named after its tier in the reader's language, and its key is derived from its id, so a page can
 * write it as an option value without showing the id.
 *
 * The initial term is read as one period of the tier from the day the subscription started, which is what a tier
 * sells. An application that sells a different first term, a year at a monthly price for one, binds its own
 * {@see ListsContracts} and states it, because the package does not store what a subscription was sold as.
 */
final readonly class RunningSubscriptions implements ListsContracts
{
    public function __construct(
        private TierCatalog $tiers,
        private Repository $config,
    ) {}

    public function contractsOf(Model $owner): array
    {
        $subscriptions = Subscription::model()::query()
            ->forOwner($owner)
            ->whereNull('ends_at')
            ->whereNull('terminated_at')
            ->whereIn('status', [
                SubscriptionState::Active->value,
                SubscriptionState::Trialing->value,
                SubscriptionState::PastDue->value,
                SubscriptionState::Paused->value,
            ])
            ->orderBy('id')
            ->get();

        $choices = [];

        foreach ($subscriptions as $subscription) {
            $choices[] = new ContractChoice(
                key: self::keyOf($subscription),
                name: $this->nameOf($subscription),
                subscription: $subscription,
                initialTermEndsAt: $this->initialTermEndOf($subscription),
            );
        }

        return $choices;
    }

    /** The option value of a subscription: stable for as long as the subscription exists, and naming nothing. */
    public static function keyOf(Subscription $subscription): string
    {
        $id = $subscription->getKey();

        return substr(hash('sha256', 'billing-contract|'.(is_scalar($id) ? (string) $id : '')), 0, 20);
    }

    /** The tier's label in the reader's language, inside a phrase that says it is a subscription. */
    private function nameOf(Subscription $subscription): string
    {
        $tier = $subscription->tier_key;
        $label = $tier === null ? '' : trim(CatalogLabel::translate($this->tiers->label($tier)));
        $name = $label === ''
            ? Lang::get('billing::declarations.contract_unnamed')
            : Lang::get('billing::declarations.contract_name', ['tier' => $label]);

        return is_string($name) ? $name : $label;
    }

    private function initialTermEndOf(Subscription $subscription): ?CarbonImmutable
    {
        if ($subscription->started_at === null) {
            return null;
        }

        $interval = new TierInterval($this->config)->for($subscription->tier_key);

        return CarbonImmutable::instance($interval->advance(CarbonImmutable::instance($subscription->started_at)));
    }
}
