<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ContentReference;

/**
 * Which work, if any, a one-off purchase hands over.
 *
 * The package knows an add-on was bought and paid; it cannot know whether that add-on is a thousand credits
 * or a novel. Only the consumer's own catalog says so, which is why this is a seam and not a config table.
 *
 * The shipped implementation answers null to everything, so an install that sells no works writes no
 * ownership rows and its purchase path is byte-for-byte what it was.
 */
interface AddonContentMap
{
    /** The work this add-on hands over, or null when it hands over something else. */
    public function contentFor(string $addonKey): ?ContentReference;
}
