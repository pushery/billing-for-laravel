<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\ContentVersion;

/**
 * Where a consumer lists what it has published, so this package can work out which of it somebody is
 * entitled to.
 *
 * The package computes the POLICY; the consumer owns the versions. That split is why this seam exists at
 * all: an update rule is a question about which versions existed when, and only the consumer knows.
 *
 * Nothing here is content. A version is an opaque reference and a publication date, and the reference goes
 * back to the consumer untouched.
 */
interface ContentVersions
{
    /**
     * Every published version of a work, in any order.
     *
     * An unknown work answers with an empty list rather than throwing: a reference the catalog has never
     * heard of and a work with nothing published yet are both "there is nothing to hand over", and a
     * delivery path that had to catch an exception to render a pre-order would catch the storage failures
     * with it.
     *
     * @return list<ContentVersion>
     */
    public function versionsOf(ContentReference $content): array;
}
