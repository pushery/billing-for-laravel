<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * The jurisdiction-specific half of the go-live checklist.
 *
 * The package's core knows the SHAPE of a checklist — ordered stages, points that block or warn, points
 * that are checked or attested. It does not know that a German platform owes an OSS registration or that a
 * commission clause belongs in the terms. Those are entries of a profile, so that a consumer in another
 * country fills the same structure with their own points and never reads a single line about somebody
 * else's tax authority.
 *
 * The active profile is named by `billing.tax_profile` (default null: no profile, no jurisdiction points).
 * A consumer supplies its own by binding this contract in the container, which then wins over the name.
 */
interface JurisdictionProfile
{
    /**
     * The profile's own identifier, as it appears in `billing.tax_profile` and in the preflight report —
     * a country code for a national profile, any stable string for a consumer's own.
     */
    public function key(): string;

    /**
     * The points this jurisdiction adds to the checklist, in any order: the runner sorts them.
     *
     * @return list<GoLiveCheckpoint>
     */
    public function checkpoints(): array;
}
