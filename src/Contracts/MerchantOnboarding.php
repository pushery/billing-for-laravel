<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The receiving-side mirror of customer registration: it gives a merchant an account at the provider
 * and drives the provider's hosted identity flow.
 *
 * Nothing here moves money. Onboarding establishes WHO may receive; whether money may actually be
 * destined to them is a separate, fail-closed question answered by {@see CanReceiveMoney}. Keeping the
 * two apart is deliberate — an onboarding that returned "done" would otherwise read as permission, and
 * a provider can finish onboarding while still withholding a capability.
 *
 * The hosted flow crosses the boundary as a {@see ClientIntent}, the same value object the paying side
 * already uses for provider-shaped redirect payloads, so a consumer has one shape to handle.
 */
interface MerchantOnboarding
{
    /**
     * Create (or return) the merchant's provider account. Idempotent: called twice for the same
     * merchant it returns the stored reference instead of creating a second account — a duplicate
     * account is not a harmless retry, it splits a merchant's money across two identities.
     */
    public function createAccount(Model $merchant): MerchantAccountReference;

    /**
     * A single-use link into the provider's hosted onboarding, returning to the caller's own URLs.
     * `$refreshUrl` is where the provider sends a merchant whose link expired; `$returnUrl` is where it
     * sends them when the flow ends — which says nothing about whether it SUCCEEDED.
     */
    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent;
}
