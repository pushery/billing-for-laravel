<?php

declare(strict_types=1);

namespace Pushery\Billing\Trials;

/**
 * Which kind of trial the package grants, resolved by {@see TrialPolicy}. The three are mutually
 * exclusive for one tier: a trial is either part of the subscription, taken before there is a
 * subscription, or not offered at all.
 *
 * - None: no trial is offered.
 * - Subscription: a trial that is part of the subscription — collected at checkout via Stripe's
 *   `trial_period_days` (the mirror), or held on the local subscription row for a local-engine driver.
 * - Generic: a trial with NO subscription — granted by {@see Trials::grant()} onto the owner's own
 *   `trial_ends_at` and unlocking the configured `generic_tier` while it runs.
 */
enum TrialMode: string
{
    case None = 'none';
    case Subscription = 'subscription';
    case Generic = 'generic';
}
