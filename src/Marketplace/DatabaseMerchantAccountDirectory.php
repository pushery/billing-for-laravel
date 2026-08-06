<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The shipped directory: merchant accounts live in the package's own table.
 *
 * Both lookups are scoped to the ACTIVE provider. Without that, a marketplace that switched providers —
 * or runs a second one alongside — would resolve a merchant to an account at the wrong one and address a
 * payment to an identity that provider has never heard of.
 */
final readonly class DatabaseMerchantAccountDirectory implements MerchantAccountDirectory
{
    public function __construct(private string $provider) {}

    public function merchantForReference(string $accountReference): ?Model
    {
        $account = MerchantAccount::query()
            ->where('provider', $this->provider)
            ->where('account_reference', $accountReference)
            ->first();

        // An erased merchant takes this row with them, so an event about somebody who is gone resolves to
        // nobody rather than to whatever their id now points at.
        return $account?->merchant;
    }

    public function accountFor(Model $merchant): ?MerchantAccountReference
    {
        return MerchantAccount::query()
            ->where('provider', $this->provider)
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->first()
            ?->toReference();
    }
}
