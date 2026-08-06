<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Support\Carbon;
use Pushery\Billing\Events\MerchantAccountDeauthorized;
use Pushery\Billing\Marketplace\MerchantLifecycle;
use Pushery\Billing\Models\MerchantAccount;

/**
 * Records that a merchant disconnected their account, and ends the relationship.
 *
 * The stamp is kept on its own column rather than folded into the capability flags, because the two states
 * demand different actions. A withheld capability stops transfers and can come back; a deauthorization
 * stops transfers AND reversals, which is the state in which a clawback becomes impossible. Somebody owed
 * money by that merchant has to be able to tell which of the two happened.
 *
 * The FIRST deauthorization wins. A redelivery, or a merchant who reconnects and leaves again, must not
 * move the date forward: when the platform lost reach is the fact a dispute turns on.
 */
final readonly class MarkMerchantDeauthorized
{
    public function __construct(private MerchantLifecycle $lifecycle) {}

    public function __invoke(MerchantAccountDeauthorized $event): void
    {
        $account = MerchantAccount::query()
            ->where('provider', $event->provider)
            ->where('account_reference', $event->accountReference)
            ->first();

        if (! $account instanceof MerchantAccount) {
            return;
        }

        if ($account->deauthorized_at === null) {
            $account->forceFill(['deauthorized_at' => Carbon::now()])->save();
        }

        $this->lifecycle->terminate($account, 'The merchant disconnected their account from this platform.');
    }
}
