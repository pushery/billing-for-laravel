<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;

/**
 * One published version of a work, as the consumer's catalog reports it.
 *
 * Two fields, and neither is content: an opaque reference the consumer will hand back to itself, and the
 * moment it was published. The date is the only thing this package reasons about — every update policy is
 * ultimately a question about which versions existed when, and nothing else about a version is any of the
 * package's business.
 */
final readonly class ContentVersion
{
    public function __construct(
        /** The consumer's own identifier for this version. Never parsed here. */
        public string $reference,
        public CarbonInterface $releasedAt,
    ) {}
}
