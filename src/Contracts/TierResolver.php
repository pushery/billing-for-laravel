<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\TierIdentity;

/**
 * Resolve a billable to its tier identity. The package binds ColumnTierResolver by default (it reads
 * the denormalized tier column); an app that does not keep a tier column rebinds this to
 * SubscriptionTierResolver, which maps the active price back to a tier. Implementations MUST be
 * fail-safe to the configured zero-tier — a resolver never implies access on its own, and any doubt
 * resolves to zero rather than granting entitlements.
 *
 * The optional merchant scope answers "what tier ON creator X". It defaults to null — the platform, the
 * single-seller case — so every existing caller is unchanged. A resolver that cannot represent more than one
 * merchant (ColumnTierResolver) ignores it and stays single-seller; a marketplace rebinds to one that can.
 */
interface TierResolver
{
    public function resolve(Model $billable, ?MerchantScope $merchant = null): TierIdentity;
}
