<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

/**
 * What one reconciliation run looked at, and what it could not fully answer.
 *
 * A count alone would be the wrong return value. "Examined 400 creators" reads like coverage, and it is not
 * coverage if some of those 400 were measured against the wrong limit because nobody recorded when their
 * business started. The incomplete ones travel with the count so the caller cannot report the first without
 * the second.
 */
final readonly class SmallBusinessFlipSweepReport
{
    /**
     * @param  int  $examined  creators resting on a size relief that were reconciled
     * @param  array<string, float>  $approaching  creators nearing their limit but not over it, as
     *                                             `Morph#id` => the highest warning level reached. Not a
     *                                             defect and not an action — a heads-up, because becoming
     *                                             standard rated requires a registration that takes weeks
     * @param  list<string>  $withoutFoundingYear  those whose current-year check ran with no founding year,
     *                                             as `Morph#id` — the higher limit applied to them, so a
     *                                             creator in their founding year may be over a limit this
     *                                             run did not test them against
     */
    public function __construct(
        public int $examined,
        public array $withoutFoundingYear,
        public array $approaching = [],
    ) {}

    /** Whether every creator this run touched was measured against the limit that actually applies to them. */
    public function complete(): bool
    {
        return $this->withoutFoundingYear === [];
    }
}
