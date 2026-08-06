<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * One point on the go-live checklist: a single, named condition that is either satisfied before the
 * marketplace switch is flipped, or is not.
 *
 * A checkpoint must be CHEAP and PURE — a read of configuration, of container bindings, of the active
 * driver's shape. It must not open a socket, query a provider or touch the database. The reason is that the
 * same checklist runs in two places: as an operator command, and at boot on every request that starts the
 * framework with the marketplace on. A checkpoint that reaches over the network would turn a provider
 * outage into an application that will not boot, which is a far worse failure than the one this prevents.
 *
 * A condition that genuinely cannot be read from local state — "the terms are published", "the registration
 * was filed" — is not modeled as a check at all. It is modeled as a dated, versioned attestation the
 * operator records in configuration, which is local state again.
 *
 * Consumers implement this to add their own points and register them with the checkpoint registry.
 */
interface GoLiveCheckpoint
{
    /**
     * A stable, dotted identifier — `registrations.oss`, `configuration.chart_of_accounts`. It appears in
     * the report, in the boot refusal, and in the waiver list, so renaming one silently invalidates a
     * consumer's waiver: treat it as public API.
     */
    public function key(): string;

    /** Which stage of the checklist this point belongs to. Ordering across steps is enforced by the runner. */
    public function step(): GoLiveStep;

    /**
     * Whether an unsatisfied point stops the go-live. A blocking point failing makes the whole preflight
     * fail and every later step unreachable; a non-blocking one is reported and moves on. Non-blocking is
     * for a genuinely parallel obligation — something that must happen, but not before the first sale.
     */
    public function isBlocking(): bool;

    /**
     * Whether the operator may deliberately waive this point in configuration.
     *
     * Waivable is for a point whose subject is the operator's own jurisdiction or contract — the package
     * cannot know that a consumer's country has no equivalent obligation, so it must not be the final word.
     * Structural points are NOT waivable: a marketplace whose driver cannot route money does not become
     * able to route money because someone put its key in a list.
     */
    public function isWaivable(): bool;

    /** Read local state and report what was found, with a reason in every direction. */
    public function evaluate(): CheckpointOutcome;
}
