<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\FanReceiptTier;
use Pushery\Billing\ValueObjects\Money;

/**
 * Chooses which receipt a purchase produces, from the purchase alone.
 *
 * A buyer who asks for a full invoice gets one — that request always wins, because it is the only path that
 * collects their data and the only one they can trigger. Otherwise the choice is data-minimizing: a small
 * DOMESTIC purchase (at or below the threshold) gets a simplified receipt; everything else — a larger
 * purchase, or a cross-border one — gets a plain payment record. Neither carries buyer data.
 *
 * The threshold and what counts as domestic are a jurisdiction's rule and enter as config and a flag; the
 * resolver itself is a pure function of the amount and those two inputs, and reads no statute. The
 * comparison is inclusive at the threshold — exactly the boundary the small-amount rule draws.
 */
final readonly class FanReceiptTierResolver
{
    public function __construct(private Repository $config) {}

    public function tierFor(Money $gross, bool $isDomestic, bool $fullInvoiceRequested): FanReceiptTier
    {
        if ($fullInvoiceRequested) {
            return FanReceiptTier::FullInvoice;
        }

        $threshold = $this->config->get('billing.marketplace.receipts.small_amount_threshold_minor', 25_000);

        if ($isDomestic && is_int($threshold) && $gross->minorUnits <= $threshold) {
            return FanReceiptTier::Simplified;
        }

        return FanReceiptTier::PaymentRecord;
    }
}
