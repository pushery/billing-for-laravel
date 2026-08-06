<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View as ConcreteView;
use Livewire\Component;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\Support\SubscriptionPresenter;
use Pushery\Billing\Trials\Trials;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The base for every account-hub screen. It resolves the billing owner for the signed-in actor once —
 * the actor itself or its team, per config('billing.owner') — so no screen re-implements that decision.
 * The hub routes carry the auth middleware, so a signed-in actor is guaranteed; the guard is a
 * fail-closed backstop.
 */
abstract class AccountScreen extends Component
{
    /** The billing owner for the signed-in actor. */
    protected function owner(): Model
    {
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        return Container::getInstance()->make(BillingEntityResolver::class)->ownerFor($actor);
    }

    /**
     * The signed-in actor — the specific user who clicked, which is NOT the owner when billing is
     * team-owned. Audit rows record both, so the trail says "Ada canceled the team's plan", not "the team
     * canceled itself".
     */
    protected function actor(): Model
    {
        // The same fail-closed check owner() makes, and it is NOT redundant with it. audit() resolves
        // owner() first in one argument list, so on that path this branch is indeed already refused —
        // but ConfirmsIdentity::confirmIdentity() calls actor() on its own, at the START of the
        // irreversible actions, before any owner() lookup. A Livewire action is its own request, so a
        // session that ends between the mount and the click arrives exactly there.
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        return $actor;
    }

    /**
     * Record a customer-initiated audit event: the acting user did $type to the owner's billing.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function audit(string $type, array $payload = []): void
    {
        Container::getInstance()->make(BillingEventLog::class)->record($type, $this->owner(), $payload, AuditSource::Customer, $this->actor());
    }

    /** The owner's current tier key from its denormalized tier column (fail-safe to the zero tier). */
    protected function currentTierKey(): string
    {
        $config = Container::getInstance()->make(Repository::class);

        $column = $config->get('billing.tier_column', 'plan');
        $zero = $config->get('billing.zero_tier', 'free');
        $zero = is_string($zero) ? $zero : 'free';

        $value = $this->owner()->getAttribute(is_string($column) ? $column : 'plan');

        return is_string($value) && $value !== '' ? $value : $zero;
    }

    /** The owner's default subscription row, or null when there is no local row yet. */
    protected function subscription(): ?Subscription
    {
        $owner = $this->owner();

        return Subscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->forMerchant(null)
            ->where('type', 'default')
            ->latest('id')
            ->first();
    }

    /**
     * The canonical state of the owner's billing. With a subscription row it is presented from that; with
     * none, from the owner itself — so an owner on a generic trial (no subscription) resolves to
     * GenericTrial rather than being read as never-subscribed.
     *
     * $pendingActivation is the post-checkout signal: a customer just back from checkout whose subscription
     * has not been recorded yet resolves to Activating (a transient state the screen polls out of), rather
     * than the "never subscribed" it would otherwise read.
     */
    protected function currentState(bool $pendingActivation = false): SubscriptionState
    {
        $subscription = $this->subscription();

        $snapshot = $subscription instanceof Subscription
            ? $subscription->toSnapshot()
            : Container::getInstance()->make(Trials::class)->ownerSnapshot($this->owner());

        return Container::getInstance()->make(SubscriptionPresenter::class)->present($snapshot, $pendingActivation);
    }

    /**
     * Whether there is a PROVIDER subscription to mutate — the honest test for "can this owner swap?".
     * Keyed on the provider reference, not the tier column: an owner may sit on a tier (a comp, a
     * column set by hand) with no subscription at Stripe, and swapping one that does not exist 500s.
     */
    protected function hasLiveSubscription(): bool
    {
        return $this->subscription()?->provider_id !== null;
    }

    /**
     * The fail-closed money-eligibility guard. A money-initiating action calls this first, so the
     * package never starts a money flow for an owner the app's eligibility gate denies (the default
     * gate allows everyone; an app binds a stricter one).
     */
    protected function ensureEligible(): void
    {
        if (! (Container::getInstance()->make(CanTransactMoney::class)->check($this->owner()))) {
            throw new HttpException(403);
        }
    }

    /**
     * Wrap a screen's view in the configured full-page layout (config('account.layout'); the package's
     * self-contained layout by default). Every screen renders through this so the hub actually mounts
     * full-page — a bare Livewire view has no layout and would fail with "No hint path for [layouts]".
     *
     * @param  view-string  $name
     * @param  array<string, mixed>  $data
     */
    protected function view(string $name, array $data = []): View
    {
        // Fail-closed backstop on EVERY screen render: no account-hub screen renders for an
        // unauthenticated visitor, even one (like DangerZone) whose render does not otherwise resolve the
        // owner, and even if a route were ever mounted without the auth middleware. owner()/actor() enforce
        // the same on data access; centralizing it here means a new screen inherits the guard automatically.
        if (! (Auth::user() instanceof Model)) {
            throw new HttpException(403);
        }

        $layout = Container::getInstance()->make(Repository::class)->get('account.layout', 'billing::layouts.account');

        // The factory is DOCUMENTED as returning the View CONTRACT, but the object it really hands back is
        // the concrete Illuminate\View\View -- and that concrete class is where Livewire registers
        // ->layout() as a macro (SupportPageComponents). So the concrete type is named here: the contract
        // genuinely does not carry the method, and nothing else in the chain reveals which class does.
        /** @var ConcreteView $rendered */
        $rendered = ViewFacade::make($name, $data);

        /** @var View $view */
        $view = $rendered->layout(is_string($layout) ? $layout : 'billing::layouts.account');

        return $view;
    }
}
