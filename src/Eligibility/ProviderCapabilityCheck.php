<?php

declare(strict_types=1);

namespace Pushery\Billing\Eligibility;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * The package's own receiving check: the provider has confirmed this merchant can take charges, receive
 * payouts, and has finished submitting their details.
 *
 * It NEVER throws. A check is one voice in a composed gate, and a check that threw would turn a merchant
 * who simply has not onboarded into an error page — so an absent account, a stale account and a
 * half-onboarded account all answer the same way: no.
 *
 * A consumer adds their own predicates beside this one (verified, of age, not suspended). The two kinds are
 * deliberately separate: this one reports what the PROVIDER established and nothing more, so a platform
 * rule can never be mistaken for a provider fact, or the reverse.
 */
final readonly class ProviderCapabilityCheck
{
    public function __construct(private MerchantAccountDirectory $accounts) {}

    public function __invoke(Model $merchant): bool
    {
        $account = $this->accounts->accountFor($merchant);

        // No account on file means the merchant never onboarded, or their record is gone. Either way the
        // provider has confirmed nothing, and "nothing confirmed" is the case fail-closed exists for.
        return $account instanceof MerchantAccountReference && $account->isReceivable();
    }
}
