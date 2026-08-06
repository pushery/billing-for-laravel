<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\AccessVia;
use Pushery\Billing\Enums\ContentAvailability;

/**
 * One answer to "may this person reach this work right now, and through what".
 *
 * ## Four facts, deliberately not collapsed into one boolean
 *
 * `granted` says whether there is a claim. `availability` says whether the work can be handed over.
 * `via` says which claim it is. `version` says what was promised about later versions. A consumer that only
 * wants a yes/no reads `granted` and ignores the rest — but the rest exists because every screen that shows
 * a library eventually needs it, and a boolean-only seam forces each of them to re-derive it from the raw
 * rows, differently.
 *
 * The pair that matters most is `granted` + `availability`: owning a work that has been taken down is the
 * ordinary case, not an error, and the two fields are what let a screen say "you own this, it is currently
 * unavailable" instead of "you do not own this".
 *
 * ## What it deliberately does not carry
 *
 * No legal vocabulary and no jurisdiction. Whether a purchase can still be withdrawn from is a different
 * question with a different answer path — this one is "may they see it NOW". Two gates for one thing is two
 * gates that eventually disagree, so the withdrawal gate sits on the write side, where a grant is created,
 * and this reader never re-checks it: if the row exists, the declaration behind it exists by construction.
 */
final readonly class AccessDecision
{
    public function __construct(
        public bool $granted,
        public ?AccessVia $via,
        public VersionResolution $version,
        public ContentAvailability $availability,
    ) {}

    /**
     * No claim at all: nothing owned, no subscription covering it, or the register switched off.
     *
     * `via` is null rather than some "none" case — there is no source to name, and inventing one would put a
     * value into `via` that a consumer could switch on and act upon.
     */
    public static function denied(ContentAvailability $availability = ContentAvailability::Available): self
    {
        return new self(false, null, VersionResolution::latest(), $availability);
    }

    /**
     * Whether this access can be taken away, as opposed to running out on its own.
     *
     * Delegated to the source rather than stored: a refund revokes a grant, while a subscription simply
     * lapses, and a stored flag would be a second place for that distinction to be got wrong.
     */
    public function isRevocable(): bool
    {
        return $this->granted && $this->via instanceof AccessVia && $this->via->isRevocable();
    }

    /** Granted AND handable-over — the single question a delivery path asks before streaming bytes. */
    public function isDeliverable(): bool
    {
        return $this->granted && $this->availability->isDeliverable();
    }
}
