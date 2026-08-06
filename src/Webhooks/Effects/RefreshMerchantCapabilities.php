<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Enums\MerchantStatus;
use Pushery\Billing\Events\MerchantAccountUpdated;
use Pushery\Billing\Marketplace\MerchantCapabilities;
use Pushery\Billing\Marketplace\MerchantLifecycle;
use Pushery\Billing\Models\MerchantAccount;

/**
 * Stores what the provider reported, then moves the merchant's standing to match.
 *
 * The store is the only path by which a capability flag ever changes, which is what makes the flags
 * trustworthy on the money path: nothing the platform does raises one. A stale flag is not cosmetic — a
 * merchant who lost their payout capability yesterday keeps being paid until this runs.
 *
 * The standing is derived here rather than read from the flags at routing time, because a provider sends
 * several reports during one verification. Deriving live would suspend and reinstate a merchant on each of
 * them, and every downstream consumer — a notice to the merchant, a subscription policy, an operator alert
 * — would fire on every one. A transition happens when something actually moved.
 *
 * Reinstatement is deliberately NOT symmetric with suspension: a terminated merchant stays terminated,
 * because a provider keeps reporting healthy capabilities for an account long after its owner disconnected
 * it from this platform.
 */
final readonly class RefreshMerchantCapabilities
{
    public function __construct(
        private MerchantCapabilities $capabilities,
        private MerchantLifecycle $lifecycle,
    ) {}

    public function __invoke(MerchantAccountUpdated $event): void
    {
        $account = $this->capabilities->apply($event->account);

        if (! $account instanceof MerchantAccount) {
            return;
        }

        // What the PROVIDER says, on its own. The platform's own position is what this decides, so reading
        // the combined answer here would make the decision depend on its own previous outcome.
        $providerPermits = $account->charges_enabled
            && $account->payouts_enabled
            && $account->details_submitted
            && $account->deauthorized_at === null;

        if (! $providerPermits) {
            $this->lifecycle->suspend($account, 'The provider withheld a capability this merchant needs to receive money.');

            return;
        }

        if ($account->status === MerchantStatus::Suspended) {
            $this->lifecycle->reinstate($account);
        }
    }
}
