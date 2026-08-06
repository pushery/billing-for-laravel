<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;

/**
 * A jurisdiction profile that also carries its country's rates.
 *
 * A marker a profile opts into rather than a method on every profile, so adding it breaks no existing
 * implementation — the same shape as {@see RequiresTaxStatusHold}, and for the same reason: the contract
 * every profile satisfies is about the go-live checklist, and widening it would be a fatal error in a
 * consumer's own profile class.
 *
 * What it buys is that an operator in a jurisdiction the package ships does not hand-type their own
 * country's rates into configuration. Hand-typed rates are wrong in a way nothing catches: a wrong rate
 * looks exactly like a right one on an invoice, and the mistake surfaces at the tax return rather than at
 * the sale. Configuration still wins where it is present, because an operator who has priced their own
 * table has a reason the package cannot know — a rate the package has not caught up with, most obviously.
 *
 * The core stays free of any of it. Nothing here names a country or a rate; the profile does, which is what
 * lets a consumer elsewhere supply theirs and read no foreign statute.
 */
interface SuppliesTaxRates
{
    /**
     * The rates this jurisdiction charges, as country → category → basis points.
     *
     * Keyed by country rather than fixed to the profile's own, because a jurisdiction that files one return
     * for supplies into many countries — a one-stop-shop scheme is the obvious case — owes those countries'
     * rates rather than its own, and the profile is where that knowledge belongs.
     *
     * @return array<string, array<string, int>>
     */
    public function taxRates(): array;

    /** The day those rates were known to be correct, so their age can be reported rather than assumed. */
    public function taxRatesValidFrom(): CarbonInterface;
}
