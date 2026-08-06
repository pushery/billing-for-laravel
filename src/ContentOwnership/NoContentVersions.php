<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Pushery\Billing\Contracts\ContentVersions;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\ContentVersion;

/**
 * The shipped version list: nothing is published.
 *
 * The package holds no content, so it can only ask — and with nothing wired there is nobody to ask. An empty
 * list is the honest answer, and it produces the pre-order shape everywhere downstream: a work somebody owns
 * with no version to hand over yet. That is a state the model already expresses, so nothing has to special-
 * case the absence.
 *
 * It exists because the seam was documented and left UNBOUND, which meant resolving it threw — a contract a
 * reader could find and a consumer could not use. Same failure class the register's other seams were built
 * to avoid, introduced by the same hand in the same run.
 */
final readonly class NoContentVersions implements ContentVersions
{
    /** @return list<ContentVersion> */
    public function versionsOf(ContentReference $content): array
    {
        return [];
    }
}
