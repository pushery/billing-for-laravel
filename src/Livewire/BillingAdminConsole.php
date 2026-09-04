<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\View as ConcreteView;
use Livewire\Component;
use Pushery\Billing\Exceptions\InvalidDatevBatch;
use Pushery\Billing\Invoicing\DatevPeriodBatch;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Reporting\BillingMetricsReporter;
use Pushery\Billing\Support\BillingAdmin;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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

    /** Owner id for the cancel action — client input; the action re-authorizes and resolves it. */
    public string $cancelOwnerId = '';

    /** The outcome of the last cancel action ('canceled' | 'not_found'). */
    public ?string $cancelResult = null;

    /** Period bounds for the booking-batch export — client input, re-read and re-validated by the action. */
    public string $datevFrom = '';

    public string $datevTo = '';

    /** The outcome of the last export attempt ('invalid_period' | 'refused' | 'unbalanced'), and its detail where there is one. */
    public ?string $datevResult = null;

    public string $datevRefusal = '';

    /** The two totals and their difference, in the operator's own terms, when a batch does not tie out. */
    public string $datevImbalance = '';

    /**
     * Whether the operator has been shown an imbalance for THIS period and asked for the file anyway.
     *
     * Held per period rather than per session, and cleared whenever a bound moves: an acknowledgement
     * carried across a change of dates would hand somebody a different unbalanced month in silence, which
     * is precisely the outcome the first refusal existed to prevent.
     */
    public bool $datevImbalanceAcknowledged = false;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function render(): View
    {
        // Fail-closed on every render, not just mount — a re-render must never leak metrics/audit to a
        // visitor whose admin grant was revoked between requests.
        $this->authorizeAdmin();

        // ->layout() is Livewire's full-page wrapper, registered as a macro on the CONCRETE view class --
        // the contract the factory is documented against does not carry it. Same naming as
        // AccountScreen::view(). Without a layout a routed Livewire view has no page shell.
        /** @var ConcreteView $rendered */
        $rendered = ViewFacade::make('billing::livewire.billing-admin-console', [
            'metrics' => Container::getInstance()->make(BillingMetricsReporter::class)->compute(),
            'events' => BillingEvent::query()->latest('id')->limit(50)->get(),
        ]);

        /** @var View $view */
        $view = $rendered->layout('billing::layouts.admin');

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

        $owner = $this->resolveOwner($this->compOwnerId);

        if (! $owner instanceof Model) {
            $this->compResult = 'not_found';

            return;
        }

        Container::getInstance()->make(BillingAdmin::class)->comp($owner, $tier, 'admin console', Auth::user());

        $this->compResult = 'granted';
        $this->reset('compOwnerId', 'compTier');
    }

    /**
     * End an owner's subscription immediately — the support case the console could not reach.
     *
     * `BillingAdmin` builds three out-of-band operations and the shipped console offered one. A support
     * agent asked to end a subscription — an abuse case, a threatened chargeback, a customer on the phone —
     * had no path to it in the package at all. The alternatives were both bad: the provider's own dashboard,
     * where the local row drifts and this package's audit trail stays empty, or consumer code reinventing an
     * authorization gate that already exists and already fronts the at-least-as-consequential comp action.
     *
     * Re-authorized like every other entry point, and an unknown owner is REPORTED rather than fataled —
     * the same graceful outcome the comp action promises for the same input.
     */
    public function cancel(): void
    {
        $this->authorizeAdmin();

        $owner = $this->resolveOwner($this->cancelOwnerId);

        if (! $owner instanceof Model) {
            $this->cancelResult = 'not_found';

            return;
        }

        Container::getInstance()->make(BillingAdmin::class)->cancel($owner, 'admin console', Auth::user());

        $this->cancelResult = 'canceled';
        $this->reset('cancelOwnerId');
    }

    /** Whether the app declares this tier in billing.tiers — existence, not resolvability (mirrors GrantTierCommand). */
    private function tierExists(string $tier): bool
    {
        $tiers = Config::get('billing.tiers');

        return is_array($tiers) && array_key_exists($tier, $tiers);
    }

    /**
     * Resolve one of the console's owner-id inputs to a model.
     *
     * Takes the id rather than reading a fixed property: two actions now resolve an owner, and a helper
     * hard-wired to one of them would have meant a second copy of the malformed-id handling below — which is
     * the part that keeps a crafted request from 500-ing the console.
     */
    private function resolveOwner(string $ownerId): ?Model
    {
        $model = Config::get('billing.customer.model');
        $id = trim($ownerId);

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

    /**
     * Hand the accountant a period's booking batch, from the browser instead of a shell.
     *
     * ## It runs the SAME assembly as the command, and that is the whole design
     *
     * The batch is put together by `DatevPeriodBatch`, which the scheduled command also calls. A second
     * assembly here would drift, and the drift is the invisible kind: this export has twice shipped a
     * batch that was structurally valid, imported cleanly, and was short an entire category of bookings
     * nobody enumerates. The refusals in the writer are reached for the same reason — a screen that caught
     * them and reported success would emit exactly the file they exist to prevent.
     *
     * ## A refusal produces a MESSAGE and no file
     *
     * That asymmetry matters more than it looks. The writer refuses rather than emits precisely because the
     * import will not argue: a truncated reference lands as a booking pointing at nothing, and a batch
     * spanning two posting periods puts half of itself in the wrong month. Neither surfaces as an error
     * anywhere — they surface as a reconciliation that does not close, months later. A partial download
     * would be the worst outcome available, so nothing is streamed unless the whole batch was rendered.
     *
     * ## Nothing is written to disk
     *
     * Streamed straight to the browser. That is not only convenience: a booking batch is the operator's
     * complete revenue history for the period in one file, and a copy left in storage is a second place it
     * has to be protected, retained and erased from. There is no such copy, so there is no such question.
     */
    public function exportDatev(): ?StreamedResponse
    {
        // Re-authorized like every other entry point: a crafted request to this action must be refused even
        // if a render was somehow reached, and the whole revenue history is what is on the other side.
        $this->authorizeAdmin();

        $this->datevResult = null;
        $this->datevRefusal = '';
        $this->datevImbalance = '';

        try {
            $from = CarbonImmutable::parse($this->datevFrom)->startOfDay();
            $to = CarbonImmutable::parse($this->datevTo)->endOfDay();
        } catch (Throwable) {
            $this->datevResult = 'invalid_period';

            return null;
        }

        if ($to->lessThan($from)) {
            $this->datevResult = 'invalid_period';

            return null;
        }

        // Resolved here rather than declared as a parameter: the component framework fills an action's
        // parameters from the CLIENT, not from the container, so a type-hinted dependency arrives as null
        // and the action fails before it authorizes anything.
        $batch = Container::getInstance()->make(DatevPeriodBatch::class);

        try {
            $rendered = $batch->render($from, $to);
        } catch (InvalidDatevBatch $refused) {
            // The writer's own words, not a generic failure. It refuses over a specific document reference
            // or a specific period boundary, and an operator who is told which one can fix it; one who is
            // told "export failed" reruns it and gets the same nothing.
            $this->datevResult = 'refused';
            $this->datevRefusal = $refused->getMessage();

            return null;
        }

        // The batch does not tie out, and nobody has said "give it to me anyway" for THIS period.
        //
        // The command writes the file and fails; this refuses the download and says why, and the two agree
        // in substance: the file is one click away, not withheld. The difference is what a download IS. It
        // lands in a folder without being read and gets forwarded from there, so an unbalanced batch that
        // arrives silently is an unbalanced batch at the accounting firm. A message beside a file the
        // operator already has is a message nobody needs to read.
        //
        // Refusing also sidesteps a mechanism this screen should not depend on: whether component state set
        // before a raw streamed response ever reaches the client is not something the code can answer, and
        // a warning that may or may not be rendered is worse than none.
        if (! $rendered['reconciliation']->isBalanced() && ! $this->datevImbalanceAcknowledged) {
            $reconciliation = $rendered['reconciliation'];

            // Both totals, never only the difference: "off by 12.50" does not say which side is short, and
            // the two point at opposite defects.
            $this->datevResult = 'unbalanced';
            // The Translator through the container, not the `__()` helper. That helper lives in Foundation,
            // which this package deliberately does not require — `LeanDependencyContractTest` fails on it,
            // and it is right to: a consumer who installs the split components would get a fatal here.
            $this->datevImbalance = Container::getInstance()->make(Translator::class)->get('billing::admin.datev.imbalance_figures', [
                'subledger' => $reconciliation->subLedgerTotal->toDecimal().' '.$reconciliation->subLedgerTotal->currency,
                'batch' => $reconciliation->collectiveAccountBalance->toDecimal().' '.$reconciliation->collectiveAccountBalance->currency,
                'difference' => $reconciliation->difference()->toDecimal().' '.$reconciliation->difference()->currency,
            ]);

            return null;
        }

        $content = $rendered['content'];
        $name = 'datev-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return new StreamedResponse(static function () use ($content): void {
            echo $content;
        }, 200, [
            'Content-Type' => 'text/csv; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * "I have seen the difference; give me the file."
     *
     * A separate action rather than a flag on the form, because it must not be settable before the figures
     * have been shown: a checkbox next to the button would let somebody arm it once and never see another
     * imbalance.
     */
    public function exportDatevAnyway(): ?StreamedResponse
    {
        $this->authorizeAdmin();

        $this->datevImbalanceAcknowledged = true;

        return $this->exportDatev();
    }

    /** A changed bound is a different period, and an acknowledgement never carries across one. */
    public function updatedDatevFrom(): void
    {
        $this->forgetTheImbalance();
    }

    public function updatedDatevTo(): void
    {
        $this->forgetTheImbalance();
    }

    private function forgetTheImbalance(): void
    {
        $this->datevImbalanceAcknowledged = false;
        $this->datevImbalance = '';

        if ($this->datevResult === 'unbalanced') {
            $this->datevResult = null;
        }
    }

    private function authorizeAdmin(): void
    {
        $ability = Config::get('billing.admin.ability', 'billing-admin');

        if (! (Gate::allows(is_string($ability) ? $ability : 'billing-admin'))) {
            throw new HttpException(403);
        }
    }
}
