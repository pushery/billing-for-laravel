<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a customer reference WITHIN the merchant account that issued it.
 *
 * The package's ordinary {@see CustomerDirectory} is global, which is right for a single seller and quietly
 * wrong once a second merchant account exists: provider customer ids are unique per account, so the same id
 * can belong to two different people under two merchants. A global lookup then returns whichever it finds
 * first — not a failed lookup anybody notices, but a webhook attributed to a stranger, and with it their
 * invoice, their receipt and their data.
 *
 * Kept as its own contract rather than an extra argument on the global one, so no existing caller can
 * accidentally get the multi-account semantics, and no marketplace caller can accidentally miss them.
 */
interface MerchantScopedCustomerDirectory
{
    /** The buyer a customer reference means inside this merchant account, or null when none is on file. */
    public function ownerForReference(string $accountReference, string $customerReference): ?Model;
}
