<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\UpdatePolicy;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * Where a consumer says which update policy a work is sold under — per work, and per merchant.
 *
 * The policy is the creator's decision, and creators make it at two levels: "this book is frozen" and
 * "everything I publish gets updates for a year". Both live in the consumer's own product data, so both are
 * asked here rather than configured in this package.
 *
 * Two methods rather than one so the layering is visible and testable from here. A single "give me the
 * policy" method would push the precedence into every implementation, and precedence that lives in several
 * places is precedence that eventually differs between them.
 *
 * Null means "no preference at this level" — not a policy. It is what lets the next level answer.
 */
interface UpdatePolicyCatalog
{
    /** The policy this specific work is sold under, or null to fall through to the merchant's default. */
    public function policyForContent(ContentReference $content): ?UpdatePolicy;

    /** The merchant's own default, or null to fall through to the package's configured default. */
    public function policyForMerchant(?MerchantScope $merchant): ?UpdatePolicy;
}
