<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\ValueObjects\ContentReference;

/**
 * The shipped map: no add-on hands over a work.
 *
 * Most installs sell credits, seats or usage, and for them the ownership register should stay empty however
 * many one-off purchases go through. Answering null to everything is what keeps their purchase path
 * byte-for-byte what it was — the register is opt-in at the level of "which of your products is a work",
 * not only at the level of a config flag.
 */
final readonly class NoAddonContent implements AddonContentMap
{
    public function contentFor(string $addonKey): ?ContentReference
    {
        return null;
    }
}
