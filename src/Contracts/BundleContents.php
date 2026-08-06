<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ContentReference;

/**
 * What is in a bundle right now — asked, never stored.
 *
 * A bundle is the consumer's own grouping, and its contents change: a creator adds a track to a collection,
 * removes a chapter, replaces an edition. Storing a copy of the membership here would freeze a moment and
 * then disagree with the catalog forever after, so the package asks each time it needs to know.
 *
 * "Right now" is the whole reason the additive question exists at all. See `ContentGrants::grantBundle()`.
 */
interface BundleContents
{
    /**
     * The works currently in a bundle, in any order.
     *
     * An unknown bundle answers with an empty list rather than throwing — a bundle nobody has defined and
     * one that is genuinely empty are the same thing to a caller, and neither is a failure.
     *
     * @return list<ContentReference>
     */
    public function worksIn(string $bundleReference): array;
}
