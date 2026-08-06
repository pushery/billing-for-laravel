<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\AccessDecision;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\OwnedWork;

/**
 * The read seam a consumer asks "may this person reach this work right now, and through what".
 *
 * ## The boundary, restated because this is where it is easiest to cross
 *
 * Billing owns the ownership REGISTER and composes the live answer. The consumer owns the content bytes, the
 * versions, the mapping from a tier to the works it covers, and the delivery. **This package never stores
 * content and never decides what a tier unlocks** — it cannot, and a package that guessed would hand out
 * somebody else's work.
 *
 * ## Composition is a union, and the union is what makes it correct
 *
 * Access is granted when EITHER a persisted grant says so OR the live subscription view does. The two answer
 * different questions and neither subsumes the other: ownership is permanent and narrow, a subscription is
 * broad and temporary. Requiring both would take a bought work away the day somebody cancels; requiring only
 * the subscription would do the same. The union is the only reading that survives a cancellation.
 *
 * The subscription half is NEVER persisted. It is resolved on every read from the subscription state plus
 * the consumer's own tier-to-content scope, because the moment it were written down it would be a fact that
 * outlives the state it came from — the exact mistake the register avoids by having no subscription source.
 *
 * ## Fail-closed
 *
 * With the register switched off, or with no consumer scope wired, the answer is no. A read seam whose
 * default was yes would be a permission system that starts open.
 */
interface ContentAccessReader
{
    /**
     * The decision for one work, at an instant (now, when none is given).
     *
     * Never throws for an unknown reference: not owning something and it not existing are both answered as a
     * decision, because a delivery path that had to catch an exception to render "you do not own this" would
     * catch the storage failures with it.
     */
    public function accessFor(Model $principal, ContentReference $content, ?CarbonInterface $on = null): AccessDecision;

    /**
     * Every work this person holds a REGISTERED claim on, keyed by `ContentReference::key()`.
     *
     * Registered, not reachable: a subscription can make thousands of works readable, and enumerating them
     * would mean asking the consumer to list its whole catalog. This is the "my downloads" read — the things
     * that stay when everything else lapses — and each entry carries the same decision `accessFor` would
     * give, so a screen never re-resolves one row at a time.
     *
     * Each entry carries the same decision `accessFor` would give, plus the facts on the row a library screen
     * needs — how it was come by, when, whether it ends, which bundle it arrived with. Returning decisions
     * alone would send every consumer straight back to the rows to render a single line.
     *
     * Answered without an N+1: one query for the rows, one for the subscription state, one batched call to
     * the catalog, however many works are involved.
     *
     * @return array<string, OwnedWork>
     */
    public function grantsFor(Model $principal, ?CarbonInterface $on = null): array;
}
