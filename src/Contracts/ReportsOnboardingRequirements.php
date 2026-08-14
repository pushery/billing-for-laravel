<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * An onboarding implementation that can say what the PROVIDER is still waiting for.
 *
 * ## Why this is its own interface and not two more methods on `MerchantOnboarding`
 *
 * That contract is public surface a consumer may implement. Adding a method to it would break every
 * implementation the day this package upgraded, with a fatal error and nothing to do about it but write
 * the method — the classic reason an interface stops being safe to extend. Segregated, an implementation
 * opts in, and one that does not simply answers nothing here.
 *
 * ## Why the answer is not on `MerchantAccountReference`
 *
 * That value is a SNAPSHOT, refreshed by webhook, and its three flags are all a routing decision needs.
 * What is still outstanding is a different question with a different lifetime: it changes as a merchant
 * works through the provider's form, nobody routes money on it, and reading it means a live call. Putting
 * it on the snapshot would either make the snapshot stale in a new way or make every read of it an
 * HTTP request.
 */
interface ReportsOnboardingRequirements
{
    /**
     * What the provider still needs from this merchant, as provider-defined requirement keys.
     *
     * An empty list is the honest answer when the provider is waiting for nothing — which is not the same
     * as the account being ready, because a provider can also still be reviewing what it already has.
     *
     * Null means this merchant has no account at the provider yet, so there is nothing to be outstanding.
     *
     * @return array{currently_due: list<string>, eventually_due: list<string>, disabled_reason: ?string}|null
     */
    public function outstandingFor(Model $merchant): ?array;
}
