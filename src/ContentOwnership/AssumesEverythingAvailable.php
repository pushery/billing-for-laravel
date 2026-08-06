<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Pushery\Billing\Contracts\ContentCatalog;
use Pushery\Billing\Enums\ContentAvailability;
use Pushery\Billing\ValueObjects\ContentReference;

/**
 * The shipped catalog: every work is reported available.
 *
 * The name is the documentation. This package does not hold the works, so with no catalog wired it has
 * nothing to ask and exactly one honest thing to say — that it knows of no reason the work is missing. It
 * does NOT know the work is there.
 *
 * The direction is deliberate and is the opposite of the scope's. Availability is not a permission: reported
 * wrongly it produces a broken download in the consumer's own delivery path, which the consumer sees
 * immediately. Defaulting it to "gone" would instead show every owned work as taken down in a library that
 * simply never wired a catalog — a false alarm about the one thing a buyer is most sensitive to.
 */
final readonly class AssumesEverythingAvailable implements ContentCatalog
{
    /**
     * @param  list<ContentReference>  $references
     * @return array<string, ContentAvailability>
     */
    public function availabilityOf(array $references, CarbonInterface $at): array
    {
        $availability = [];

        foreach ($references as $reference) {
            $availability[$reference->key()] = ContentAvailability::Available;
        }

        return $availability;
    }
}
