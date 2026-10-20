<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Webhooks\RepeatableEffect;

/**
 * Writes down where a routed sale paid on the provider's page went, from the address the buyer entered there.
 *
 * The session's row is written before the buyer pays, when nobody knows the address yet, and the platform's records of
 * a sale it only arranged read the place of the supply from that row (Art. 54c(2) Implementing Regulation 282/2011).
 * The completed session reports the country, and this writes it once: a country the row already names is kept, so a
 * redelivery or a replay changes nothing.
 */
final readonly class RecordTheDestinationOfARoutedSale implements RepeatableEffect
{
    public function __construct(private RoutedChargeLedger $ledger) {}

    public function __invoke(AddonPurchased $event): void
    {
        if ($event->provider === null || $event->paymentReference === null || $event->buyerCountry === null) {
            return;
        }

        $charge = $this->ledger->find($event->provider, $event->paymentReference);

        if ($charge instanceof MerchantCharge) {
            $this->ledger->recordDestination($charge, $event->buyerCountry);
        }
    }
}
