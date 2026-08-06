<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;

/**
 * A jurisdiction's rules on conformity updates — the fixes a seller owes after the sale.
 *
 * ## Two axes, and this is the one nobody chooses
 *
 * A buyer's updates come on two independent axes. ENRICHMENT — new editions, added material — is what the
 * creator sells, and `UpdatePolicy` governs it: they may promise everything, a window, or nothing at all.
 * CONFORMITY — a defect fix, a security patch, keeping the thing working on current systems — is what the
 * seller owes, and no product setting reaches it.
 *
 * Keeping them apart is the whole point of this contract existing. A single "updates" flag would make a
 * frozen sale look like a sale with no obligations attached, which is the one reading that is wrong.
 *
 * ## The core asks one question; the answers are the profile's
 *
 * How long the obligation runs, and whether it can be contracted away at all, are a jurisdiction's answers
 * and they differ. So they live here, behind a profile bound only when an operator opts into one, and the
 * neutral core never learns a statute, a period, or a word of legal vocabulary.
 */
interface ConformityUpdatePolicy
{
    /**
     * When the obligation ends for a sale made at this moment, or null when no end can be stated.
     *
     * Null is the ordinary answer, not a missing one. The obligation runs for as long as a buyer may
     * reasonably expect, and "reasonably" is a judgement about a kind of product rather than a number a
     * package can hold. An operator who has taken advice configures the period; until then the honest answer
     * is that no end has been established — and an unstated end means updates keep flowing, which is the
     * direction that cannot harm a buyer.
     */
    public function updatesUntil(CarbonInterface $acquiredAt): ?CarbonInterface;

    /**
     * Whether a waiver of this obligation is capable of being valid in this jurisdiction at all.
     *
     * Separate from whether one was actually agreed. A jurisdiction may allow a waiver only under conditions
     * a package cannot verify — agreed separately, before the contract, with the buyer told what they are
     * giving up — and may not allow one at all for security fixes. So this answers the prior question, and
     * the core additionally requires a reference to the agreement before it will record anything.
     */
    public function waiverPermitted(): bool;
}
