<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Reporting\BillingMetricsReporter;
use Pushery\Billing\Support\BillingAdmin;

/**
 * The optional, publishable admin console: billing metrics, the recent audit log, and a comp-a-tier action.
 * Every entry point — mount, render, AND the action — is authorized against the app-defined
 * `billing.admin.ability` Gate, FAIL-CLOSED: an undefined Gate denies everyone, so the console is never open
 * by accident and a crafted request to the action is refused even if the render was somehow reached.
 *
 * It is framework-agnostic plain Blade, exactly like the account hub, so the core needs no UI-kit dependency;
 * publish `billing-views` to reskin it (e.g. with your own design system's components). It registers only when
 * Livewire is installed.
 */
final class BillingAdminConsole extends Component
{
    /** Owner id + tier for the comp action — client input; the action re-authorizes and validates it. */
    public string $compOwnerId = '';

    public string $compTier = '';

    /** The outcome of the last comp action ('granted' | 'not_found' | 'invalid_tier'), so the view can report it. */
    public ?string $compResult = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function render(): View
    {
        // Fail-closed on every render, not just mount — a re-render must never leak metrics/audit to a
        // visitor whose admin grant was revoked between requests.
        $this->authorizeAdmin();

        // ->layout() is Livewire's full-page wrapper (typed as mixed on the View contract); the same @var
        // re-assert AccountScreen::view() uses. Without a layout a routed Livewire view has no page shell.
        /** @var View $view */
        $view = view('billing::livewire.billing-admin-console', [
            'metrics' => app(BillingMetricsReporter::class)->compute(),
            'events' => BillingEvent::query()->latest('id')->limit(50)->get(),
        ])->layout('billing::layouts.admin');

        return $view;
    }

    /** Comp an owner onto a tier (a support grant). Re-authorized; an unknown tier or missing owner is reported, never fataled. */
    public function comp(): void
    {
        $this->authorizeAdmin();

        $tier = trim($this->compTier);

        // Validate the tier BEFORE touching the owner, exactly as GrantTierCommand does: existence in the
        // catalog, not resolvability (the priced-free tier is a valid target). Without this an empty or
        // typo'd key would forceFill the tier column verbatim — an empty key resolves to the free zero-tier,
        // silently downgrading a paying customer while the audit trail records it as a normal grant.
        if (! $this->tierExists($tier)) {
            $this->compResult = 'invalid_tier';

            return;
        }

        $owner = $this->resolveOwner();

        if (! $owner instanceof Model) {
            $this->compResult = 'not_found';

            return;
        }

        app(BillingAdmin::class)->comp($owner, $tier, 'admin console', Auth::user());

        $this->compResult = 'granted';
        $this->reset('compOwnerId', 'compTier');
    }

    /** Whether the app declares this tier in billing.tiers — existence, not resolvability (mirrors GrantTierCommand). */
    private function tierExists(string $tier): bool
    {
        $tiers = config('billing.tiers');

        return is_array($tiers) && array_key_exists($tier, $tiers);
    }

    private function resolveOwner(): ?Model
    {
        $model = config('billing.customer.model');
        $id = trim($this->compOwnerId);

        if (! is_string($model) || ! is_a($model, Model::class, true) || $id === '') {
            return null;
        }

        try {
            return $model::query()->find($id);
        } catch (QueryException) {
            // A malformed id (a non-numeric value against an integer key raises 22P02 on Postgres) or any
            // other lookup error reports not-found instead of fataling — the console never 500s on a crafted
            // or mistyped owner id, matching the graceful outcome the design promises for an unknown owner.
            return null;
        }
    }

    private function authorizeAdmin(): void
    {
        $ability = config('billing.admin.ability', 'billing-admin');

        abort_unless(Gate::allows(is_string($ability) ? $ability : 'billing-admin'), 403);
    }
}
