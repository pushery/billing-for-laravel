<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\MerchantCharge;

/**
 * The shares held back while a merchant's payouts were withheld, and the one way they move again.
 *
 * Nothing about a withheld share is forfeited. It stays assigned to the sale and to the merchant, and each
 * run asks the payout gate again: once the reason has ended, or the money has reached the rail's own limit,
 * the share moves under the sale's own key, the same way the retry of a failed transfer moves it. Shares
 * that arrived while the withholding lasted move together with the first, so a seller who supplies their
 * data is paid everything that waited.
 *
 * A share whose transfer then fails is the retry's from there on: it carries the failure, and the release
 * leaves it alone rather than trying it twice a day from two places.
 */
final readonly class WithheldMerchantShares
{
    public function __construct(private UnmovedMerchantShares $shares) {}

    /** @return array{released: int, held: int, failed: int} */
    public function release(): array
    {
        $outcome = ['released' => 0, 'held' => 0, 'failed' => 0];

        $withheld = MerchantCharge::model()::query()
            ->where('settlement_state', SettlementState::Pending->value)
            ->whereNotNull('transfer_withheld_at')
            ->whereNull('transfer_failed_at')
            ->lazyById();

        foreach ($withheld as $charge) {
            $outcome[match ($this->shares->move($charge)) {
                'moved' => 'released',
                'failed' => 'failed',
                default => 'held',
            }]++;
        }

        return $outcome;
    }
}
