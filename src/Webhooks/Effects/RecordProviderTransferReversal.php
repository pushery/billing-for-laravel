<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Events\MerchantTransferReversedByProvider;
use Pushery\Billing\Models\MerchantCharge;

/**
 * Records a reversal the provider performed on its own, onto the sale it belongs to.
 *
 * ## Set, never add
 *
 * The event carries the provider's CUMULATIVE figure for the transfer, so the column is set to it. Adding
 * would double-count a redelivery, which is the ordinary case; and a dedup on the transfer id would freeze
 * the journal at the first reversal, which is wrong the other way — a transfer can be reversed twice, and
 * the second event carries the higher total.
 *
 * ## It never lowers a figure the platform already booked
 *
 * The platform's own refund and chargeback paths write this column too. A provider report that states LESS
 * than what is already recorded is a disagreement, and resolving it is not this effect's job — which ledger
 * wins is a separate, open question. Writing the smaller number would quietly put money back on a creator's
 * available balance on the strength of a webhook, so the larger figure stands and the disagreement is left
 * visible rather than silently settled.
 *
 * ## An unknown transfer is left alone
 *
 * A transfer this package did not create belongs to somebody else — another platform on the same provider,
 * or one made by hand. Attributing it to the nearest row would take money off a creator who had nothing to
 * do with it, which is worse than not recording it at all.
 */
final readonly class RecordProviderTransferReversal
{
    public function __invoke(MerchantTransferReversedByProvider $event): void
    {
        $charge = MerchantCharge::query()
            ->where('provider', $event->provider)
            ->where('transfer_reference', $event->transferReference)
            ->first();

        if (! $charge instanceof MerchantCharge || $event->amountReversedMinor <= $charge->transfer_reversed_minor) {
            return;
        }

        $charge->forceFill(['transfer_reversed_minor' => $event->amountReversedMinor])->save();
    }
}
