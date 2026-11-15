<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MovesTheTrialEnd;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\TrialEndNotMovable;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Moving the end of a trial where the package's own engine bills the cycles.
 *
 * The end of a trial is three columns here: `trial_ends_at`, `current_period_end`, which a trial shares with it, and
 * `scheduled_processing_at`, the moment of the first run. They move together, under the row's lock, and only while the
 * first run is still ahead: a run that is due may be collecting the trial already, and the row moved under it would
 * bill one period on two schedules.
 *
 * A direct debit announced for the old day is drawn no earlier than its notice allows, as the engine draws every
 * announced debit.
 */
final readonly class LocalTrialEnds implements MovesTheTrialEnd
{
    public function __construct(private string $provider) {}

    public function moveTheTrialEnd(Model $billable, DateTimeInterface $to, ?MerchantScope $merchant = null, ?string $type = null): Subscription
    {
        $now = CarbonImmutable::now();
        $to = CarbonImmutable::createFromInterface($to)->utc();

        if ($to <= $now) {
            throw TrialEndNotMovable::notAhead($to->toIso8601String());
        }

        $subscriptionModel = Subscription::model();

        return new $subscriptionModel()->getConnection()->transaction(function () use ($billable, $to, $now, $merchant, $type): Subscription {
            $subscription = Subscription::model()::query()
                ->forOwner($billable)
                ->where('type', $type ?? Subscription::TYPE_DEFAULT)
                ->forMerchant($merchant)
                ->where('provider', $this->provider)
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof Subscription || $subscription->status !== SubscriptionState::Trialing->value || $subscription->scheduled_processing_at?->isAfter($now) !== true) {
                throw TrialEndNotMovable::noTrial();
            }

            $subscription->update(['trial_ends_at' => $to, 'current_period_end' => $to, 'scheduled_processing_at' => $to]);

            return $subscription;
        });
    }
}
