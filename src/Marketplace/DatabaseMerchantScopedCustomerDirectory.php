<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantScopedCustomerDirectory;
use Pushery\Billing\Models\MerchantCustomer;

/**
 * The shipped account-scoped lookup: the package's own table, keyed by account AND customer.
 *
 * It never falls back to a global lookup when the scoped one misses, and that refusal is the whole point.
 * A fallback would restore exactly the ambiguity this exists to remove — and it would do so only in the
 * case where the scoped answer is unknown, which is precisely when a wrong answer is most likely.
 */
final readonly class DatabaseMerchantScopedCustomerDirectory implements MerchantScopedCustomerDirectory
{
    public function __construct(private string $provider) {}

    public function ownerForReference(string $accountReference, string $customerReference): ?Model
    {
        return MerchantCustomer::query()
            ->where('provider', $this->provider)
            ->where('account_reference', $accountReference)
            ->where('customer_reference', $customerReference)
            ->first()
            ?->owner;
    }
}
