<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantPartyResolver;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Exceptions\MerchantPartyUnavailable;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Trials\Trials;
use Pushery\Billing\ValueObjects\BannerNotice;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Resolves the single app-shell banner an owner should see about their billing, or null when nothing
 * needs their attention (the common case). It reads only local subscription rows (via the presenter)
 * plus the trial clock — no provider call — so it is cheap enough to run on every shell render and safe
 * during a provider outage. With no subscription row it reads the owner itself, so an owner on a generic
 * trial (no subscription) is nudged as their trial lapses, not left silent. Precedence: a blocked payment
 * outranks a lapsing grace period, which outranks a trial about to end.
 *
 * Every contract the owner holds is read, not only the platform's own. In a marketplace the paying
 * subscriptions are the merchants' — a creator's subscription is the creator's sale — and the dunning
 * ladder withdraws access over exactly those, so a banner that read the platform row alone could never
 * show in an install where every paid subscription belongs to a merchant.
 *
 * The precedence runs across all of them, and at equal rank the platform's own subscription wins,
 * because the account screens act on that one and so it is the only notice that carries a call to
 * action. A notice about any other contract names its merchant where one can be resolved and comes
 * without a call to action: pointing it at a screen that would report nothing to recover is a dead end.
 */
final readonly class BillingBanner
{
    /**
     * The notices in the order an owner needs them, most urgent first, with the WireKit intent, the
     * call to action and the hub route the platform subscription's notice points at.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array NOTICES = [
        'past_due' => ['danger', 'recover', 'billing.account.recovery'],
        'incomplete' => ['warning', 'confirm', 'billing.account.recovery'],
        'grace' => ['warning', 'resume', 'billing.account.subscription'],
        // Without this the pause is invisible: the owner's paid features quietly stop working and
        // nothing on the account tells them why, or that one click restores them.
        'paused' => ['warning', 'resume', 'billing.account.subscription'],
        'trial_ending' => ['info', 'upgrade', 'billing.account.plan'],
    ];

    public function __construct(
        private SubscriptionPresenter $presenter,
        private Repository $config,
        private Trials $trials,
        private MerchantPartyResolver $merchants,
    ) {}

    public function for(Model $owner): ?BannerNotice
    {
        $managed = $this->managedSubscription($owner);

        $snapshot = $managed instanceof Subscription
            ? $managed->toSnapshot()
            : $this->trials->ownerSnapshot($owner);

        $state = $this->presenter->present($snapshot);

        // Covers both a subscription trial (Trialing) and a generic trial (GenericTrial, no row): both
        // read isTrialing(), and for this one contract the trial clock falls back to the owner's column.
        $kind = $this->kindOf($state, $managed, $owner);

        $bestKind = $kind;
        $best = $kind === null ? null : new BannerNotice(
            state: $state,
            intent: self::NOTICES[$kind][0],
            messageKey: 'billing::account.banner.'.$kind,
            ctaKey: 'billing::account.banner.cta.'.self::NOTICES[$kind][1],
            ctaRoute: self::NOTICES[$kind][2],
        );

        foreach ($this->otherSubscriptions($owner, $managed) as $subscription) {
            $otherState = $this->presenter->present($subscription->toSnapshot());
            $otherKind = $this->kindOf($otherState, $subscription, null);

            if ($otherKind !== null && ($bestKind === null || $this->outranks($otherKind, $bestKind))) {
                $bestKind = $otherKind;
                $best = new BannerNotice(
                    state: $otherState,
                    intent: self::NOTICES[$otherKind][0],
                    messageKey: 'billing::account.banner.'.$otherKind,
                    ctaKey: null,
                    ctaRoute: null,
                    merchant: $this->merchantName($subscription),
                );
            }
        }

        return $best;
    }

    /**
     * Which notice a state raises, if any.
     *
     * The owner's own trial clock is asked only for the platform's subscription: a generic trial is the
     * platform's, and a merchant's contract with no trial date of its own is not ending on the owner's.
     *
     * @return key-of<self::NOTICES>|null
     */
    private function kindOf(SubscriptionState $state, ?Subscription $subscription, ?Model $owner): ?string
    {
        return match (true) {
            $state === SubscriptionState::PastDue => 'past_due',
            $state === SubscriptionState::Incomplete => 'incomplete',
            $state === SubscriptionState::Grace => 'grace',
            $state === SubscriptionState::Paused => 'paused',
            $state->isTrialing() && $this->trialEndingSoon($subscription, $owner) => 'trial_ending',
            default => null,
        };
    }

    /**
     * Whether a notice of this kind is strictly more urgent than the one already chosen. Strictly, so
     * that at equal rank the platform's own notice keeps its place and its call to action.
     *
     * @param  key-of<self::NOTICES>  $kind
     * @param  key-of<self::NOTICES>  $than
     */
    private function outranks(string $kind, string $than): bool
    {
        $order = array_flip(array_keys(self::NOTICES));

        return $order[$kind] < $order[$than];
    }

    /** The one contract the account screens act on: the platform's own, of the default type. */
    private function managedSubscription(Model $owner): ?Subscription
    {
        return Subscription::model()::query()
            ->forOwner($owner)
            ->forMerchant(null)
            ->ofDefaultType()
            ->latest('id')
            ->first();
    }

    /**
     * Every other contract the owner holds, newest first, so the newest wins a tie among them.
     *
     * A row is unique per owner, type and merchant, so this is one row per contract, not a history.
     *
     * @return Collection<int, Subscription>
     */
    private function otherSubscriptions(Model $owner, ?Subscription $managed): Collection
    {
        $query = Subscription::model()::query()->forOwner($owner);

        if ($managed instanceof Subscription) {
            $query->whereKeyNot($managed->getKey());
        }

        return $query->latest('id')->get();
    }

    /**
     * The name of the merchant a contract is with, or null for the platform's own contract of another
     * type, or where the install cannot name its merchants.
     *
     * The shipped resolver refuses to name any merchant, deliberately, because a nameless invoice is not
     * an invoice. A banner is not an invoice: it still shows, without the name, rather than taking the
     * app shell down with it.
     */
    private function merchantName(Subscription $subscription): ?string
    {
        if ($subscription->merchant_uid === MerchantScope::platform()->uid()) {
            return null;
        }

        $merchant = $subscription->loadMissing('merchant')->getRelationValue('merchant');

        if (! $merchant instanceof Model) {
            return null;
        }

        try {
            return $this->merchants->partyFor($merchant)->name;
        } catch (MerchantPartyUnavailable) {
            return null;
        }
    }

    /** Whether the trial ends within the configured warning window (default 3 days). */
    private function trialEndingSoon(?Subscription $subscription, ?Model $owner): bool
    {
        // The trial clock is the subscription's trial end when there is one; otherwise the owner's own
        // column — a generic trial has no row, and a webhook-synced trialing row carries the status but
        // sometimes no date. Checking the subscription (not its property) keeps the fallback for BOTH a
        // missing row and a row whose trial date is null.
        $endsAt = ($subscription instanceof Subscription ? $subscription->trial_ends_at : null)
            ?? ($owner instanceof Model ? $this->ownerTrialEnd($owner) : null);

        if (! $endsAt instanceof DateTimeInterface) {
            return false;
        }

        $within = $this->config->get('billing.trial.ending_within_days', 3);
        $within = is_int($within) ? $within : 3;

        $now = Carbon::now();

        return $endsAt > $now && Carbon::instance($endsAt) <= $now->copy()->addDays($within);
    }

    private function ownerTrialEnd(Model $owner): ?DateTimeInterface
    {
        $endsAt = $owner->getAttribute('trial_ends_at');

        return $endsAt instanceof DateTimeInterface ? $endsAt : null;
    }
}
