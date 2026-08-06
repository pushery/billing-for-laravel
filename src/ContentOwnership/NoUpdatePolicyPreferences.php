<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Pushery\Billing\Contracts\UpdatePolicyCatalog;
use Pushery\Billing\Enums\UpdatePolicy;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The shipped catalog: neither a work nor a merchant expresses a preference, so every sale falls through to
 * the install's configured default.
 *
 * Null here is not "unknown" and not a policy — it is the absence of one, which is what lets the next level
 * answer. An install that never wires this gets exactly one update policy, the configured one, applied to
 * everything; that is the correct behavior for a shop where the creator has no per-work setting to make.
 */
final readonly class NoUpdatePolicyPreferences implements UpdatePolicyCatalog
{
    public function policyForContent(ContentReference $content): ?UpdatePolicy
    {
        return null;
    }

    public function policyForMerchant(?MerchantScope $merchant): ?UpdatePolicy
    {
        return null;
    }
}
