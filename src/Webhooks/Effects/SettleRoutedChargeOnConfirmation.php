<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Events\RoutedChargeAbandoned;
use Pushery\Billing\Events\RoutedChargeConfirmed;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\MerchantCharge;

/**
 * Moves a routed charge off `pending` once the provider says what became of it.
 *
 * ## The gap this closes
 *
 * Two routed payments cannot settle when they are made: a card that demands 3-D Secure, and a bank debit
 * that clears days later. Both return successfully and immediately, having moved no money, and the package
 * correctly writes the merchant's row as `pending`.
 *
 * Nothing then moved it. `RoutedChargeLedger::settle()` had one caller — the synchronous path — and
 * `fail()` had none, so a pending row stayed pending for good. Three bound readers count settled rows only,
 * and one of them is the small-business turnover threshold: a merchant paid entirely by bank debit read as
 * having earned nothing, indefinitely. A threshold nobody crosses because the rows never settle is a tax
 * decision made by an omission.
 *
 * ## It is deliberately hard to reach the wrong row
 *
 * The provider's payment reference is matched against `charge_reference`, which is the value this package
 * recorded when it made the payment. An event naming a payment this package did not route matches nothing
 * and does nothing — which is what keeps the upstream mapper's broad reading safe. An ordinary one-time
 * checkout also carries no invoice, so it reaches here too, and finds nothing.
 *
 * ## Only ever from pending
 *
 * The ledger enforces it, and the reason is worth repeating where somebody reads it: a charge that settled
 * and later goes wrong is a refund or a dispute, never a failure. Marking it failed would erase the fact
 * that the money was, for a while, genuinely there. A redelivered confirmation is likewise a no-op rather
 * than a second settlement, because when the merchant's share became real is the fact a dispute turns on.
 */
final readonly class SettleRoutedChargeOnConfirmation
{
    public function __construct(private RoutedChargeLedger $ledger) {}

    public function __invoke(RoutedChargeConfirmed|RoutedChargeAbandoned $event): void
    {
        // The pending clause NARROWS the query; it does not enforce anything, and the difference is worth
        // being explicit about because a mutation removing it survives. Enforcement lives in the ledger,
        // which refuses to settle or fail anything that is not pending — one place owning the state machine
        // rather than two agreeing. Removing this line would change no outcome, only load a row to be told
        // no. It stays for the reader and the index; it is not a second guard, and it is not tested as one.
        $charge = MerchantCharge::query()
            ->where('provider', $event->provider)
            ->where('charge_reference', $event->paymentReference)
            ->where('settlement_state', SettlementState::Pending->value)
            ->first();

        if (! $charge instanceof MerchantCharge) {
            return;
        }

        if ($event instanceof RoutedChargeConfirmed) {
            // WITH the transfer the provider named, when it named one — and nothing when it did not.
            //
            // This used to settle without a reference, on the reasoning that the provider has not named one
            // yet. That is true of the separate-transfer lane, where the share moves in a later call the
            // platform makes itself. It is NOT true of a destination charge: the provider creates the
            // transfer as the payment settles and names it in the same payload. The synchronous path has
            // always carried it; this one dropped it, so a hosted checkout produced a settled row that says
            // the money moved and cannot say where to.
            //
            // That column exists to be checkable against the provider, so a placeholder would be worse than
            // null. Null still says nothing; a made-up string says something false.
            $this->ledger->settle($charge, $event->transferReference);

            return;
        }

        $this->ledger->fail($charge);
    }
}
