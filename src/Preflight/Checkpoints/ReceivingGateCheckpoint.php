<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CanReceiveMoney;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Eligibility\AlwaysReceivable;
use Pushery\Billing\Eligibility\ComposedReceiveGate;
use Pushery\Billing\Eligibility\ProviderCapabilityCheck;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * Somebody decided who may RECEIVE money, rather than the shipped default deciding it for them.
 *
 * The package binds `CanReceiveMoney` to {@see AlwaysReceivable}, whose `check()` is a literal `return
 * true`. That is the right default for a single-seller install — there are no merchants to gate — and it
 * is fail-OPEN the moment a marketplace is switched on: money then routes to accounts whose
 * `charges_enabled`, `payouts_enabled` and `details_submitted` nobody has looked at, and which may be
 * deauthorized or locally suspended.
 *
 * ## Why the checklist is the right place, and why nothing else caught it
 *
 * Running the go-live checklist is precisely the act by which an operator satisfies themselves that they
 * have forgotten nothing. It never asked this question, so it answered green — and the only place in the
 * tree that said otherwise was a comment at the binding claiming the checklist covered it.
 *
 * The failure direction is as quiet as it gets: no test goes red, nothing is logged, and the first symptom
 * is at a stranger's checkout. Stripe refuses a payment to an account that cannot receive, so the BUYER
 * sees the error rather than the merchant. In the less friendly case — an account that still receives but
 * is suspended in the operator's own records — the money simply arrives at a merchant they just blocked.
 *
 * ## Blocking, and waivable
 *
 * Blocking, because a routed sale with no receiving gate is the whole marketplace layer failing open.
 *
 * Waivable, because the package cannot judge somebody else's gate. A consumer may bind their own
 * implementation under any name and this check can only see that it is not the shipped default; refusing to
 * be waived would block every legitimate custom gate and teach operators to route around the checklist,
 * which costs more than it saves. The point is that it is SEEN.
 *
 * The gate is injected rather than resolved in the body: the checkpoint then reads nothing at all when it
 * runs, which is the purity {@see GoLiveCheckpoint} asks for stated in the signature instead of promised in
 * a comment. The registry builds it per run, so a consumer's binding is picked up on every checklist.
 */
final readonly class ReceivingGateCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private CanReceiveMoney $gate,
    ) {}

    public function key(): string
    {
        return 'configuration.receiving_gate';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return true;
    }

    public function isWaivable(): bool
    {
        return true;
    }

    public function evaluate(): CheckpointOutcome
    {
        // A single-seller install has no merchants to gate, so the shipped default is the correct answer
        // there and saying otherwise would turn a checklist into noise. This point exists for the moment the
        // switch goes on.
        if (! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return CheckpointOutcome::pass(
                'The marketplace is off, so no money is routed to a merchant and the receiving gate does not '.
                'decide anything yet.'
            );
        }

        // Compared by class name rather than with `instanceof`: the question is genuinely "is this still
        // the shipped default", which is identity rather than kind. A consumer's subclass of it would be
        // their decision and passes.
        if ($this->gate::class === AlwaysReceivable::class) {
            return CheckpointOutcome::fail(
                // The two classes are named through ::class rather than spelled out, so the message cannot
                // survive a rename of either — and so the unreferenced-class register sees a real reference
                // instead of prose. It masks comments for exactly that reason and deliberately keeps
                // strings, which is right: a class named in a string is usually live wiring. Here it now is.
                'The marketplace is on and '.CanReceiveMoney::class.' is still bound to the shipped '.
                AlwaysReceivable::class.', which admits every merchant. Bind a gate that states your own '.
                'conditions — '.ComposedReceiveGate::class.' with '.ProviderCapabilityCheck::class.' is the '.
                'fail-closed shape the package supplies — or waive this point if your own gate goes by '.
                'another name.'
            );
        }

        return CheckpointOutcome::pass(
            'A receiving gate ['.$this->gate::class.'] decides which merchants may be paid.'
        );
    }
}
