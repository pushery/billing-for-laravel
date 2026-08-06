<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Pushery\Billing\Contracts\BundleContents;
use Pushery\Billing\ValueObjects\ContentReference;

/**
 * The shipped bundle map: every bundle is empty.
 *
 * A package that guessed at bundle membership would hand out works nobody grouped together. Empty is the
 * only answer it can give, and it makes `grantBundle()` a no-op until a consumer says what their bundles
 * contain — which is the safe direction for something whose job is to create ownership.
 */
final readonly class NoBundles implements BundleContents
{
    /** @return list<ContentReference> */
    public function worksIn(string $bundleReference): array
    {
        return [];
    }
}
