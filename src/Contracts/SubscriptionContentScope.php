<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\SubscriptionGrant;

/**
 * The consumer's answer to the one question this package must not answer itself: does THIS subscription
 * cover THAT work?
 *
 * ## Why it is a seam and not a config table
 *
 * Every plausible rule here is somebody's product decision, and they contradict each other. Does a tier
 * cover the creator's back catalog or only what they publish while you are subscribed? Does a higher tier
 * include the lower one's works? Does a lapsed-then-resumed subscription reach the gap? A package that
 * picked one would be wrong for most consumers and, worse, would be wrong SILENTLY — handing out works
 * nobody meant to include.
 *
 * So the package supplies the state (which tier, on which merchant, over which window, in which state) and
 * the reference, and the consumer supplies the rule. The grant arrives whole rather than as a bare level,
 * because "cover the back catalog" needs the window, and a level alone cannot express it.
 *
 * ## The default denies everything, on purpose
 *
 * With nothing bound, no subscription covers any work. That makes the subscription half of the union inert
 * until a consumer opts in, which is the correct direction for a permission system to start in — and it is
 * why turning the register on does not, by itself, give anybody access to anything.
 */
interface SubscriptionContentScope
{
    /** Whether this subscription grant reaches this work. */
    public function covers(SubscriptionGrant $grant, ContentReference $content): bool;
}
