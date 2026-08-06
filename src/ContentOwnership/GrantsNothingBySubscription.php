<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Pushery\Billing\Contracts\SubscriptionContentScope;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\SubscriptionGrant;

/**
 * The shipped scope: no subscription covers any work.
 *
 * This is not a placeholder to be replaced by "the real one" — it is the correct default, and the name says
 * what it does so nobody has to open it to find out. Which works a tier covers is a product decision the
 * package cannot make (see `SubscriptionContentScope`), and the two ways to not make it are to refuse to
 * answer or to answer no. Refusing would mean a consumer who turns the register on cannot read anything
 * without first implementing a seam they may not need — most installs sell works outright and never want the
 * subscription half at all.
 *
 * So it answers no, and the subscription half of the union stays inert until somebody opts in. A default
 * that answered yes would make turning on a config flag hand out every work in the catalog.
 */
final readonly class GrantsNothingBySubscription implements SubscriptionContentScope
{
    public function covers(SubscriptionGrant $grant, ContentReference $content): bool
    {
        return false;
    }
}
