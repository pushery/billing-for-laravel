<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * An onboarding implementation that can ASK the provider what a merchant's capabilities are right now.
 *
 * ## What was missing
 *
 * The three capability flags had exactly one writer and one production caller: the webhook effect on
 * `MerchantAccountUpdated`. Nothing could ask. A delivery goes missing — endpoint down, wrong secret, retry
 * window closed — and the merchant sits at "cannot receive" until the provider happens to send something
 * again, with no way to find out whether we never heard or heard and were told no.
 *
 * The asymmetry is the point. Subscriptions already had `billing:sync`, whose own docblock says to use it
 * to backfill after a webhook outage. The receiving side had nothing: one ordinary operational failure,
 * planned for on one side and unhandled on the other.
 *
 * ## Why its own interface, like {@see ReportsOnboardingRequirements}
 *
 * `MerchantOnboarding` is public surface a consumer may implement. A method added there breaks every
 * implementation the day this package upgrades, fatally, with nothing to do but write it. Segregated, an
 * implementation opts in and one that does not simply answers nothing here — which the command reports by
 * name rather than dying on.
 *
 * ## It reports. It does not decide.
 *
 * The answer goes through the same single writer the webhook uses, so the invariant that only a PROVIDER
 * REPORT lifts a flag stays intact — and a deauthorization still overrides every flag the provider raises,
 * because that is the platform's own position rather than the provider's.
 */
interface ReportsMerchantCapabilities
{
    /**
     * What the provider says about this merchant's account right now.
     *
     * Null means this merchant has no account at the provider, so there is nothing to report — the same
     * answer shape {@see ReportsOnboardingRequirements::outstandingFor()} gives, for the same reason.
     */
    public function capabilitiesFor(Model $merchant): ?MerchantAccountReference;
}
